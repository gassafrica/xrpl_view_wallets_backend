<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    private const XRPL_URL = 'https://s1.ripple.com:51234/';
    private const COINGECKO_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=ripple&vs_currencies=usd';
    private const CACHE_TTL = 300; // 5m
    private const REQUEST_TIMEOUT = 15;
    private const PRICE_TIMEOUT = 10;
    private const TRANSACTION_LIMIT = 10;
    private const FALLBACK_XRP_PRICE = 0.50;
    private const XRP_DROPS_CONVERSION = 1000000;

    public function explore(Request $request): JsonResponse
    {
        try {
            Log::info('Wallet explore request received', ['address' => $request->address]);

            $this->validateRequest($request);
            $address = $request->address;
            
            $data = Cache::remember(
                "wallet_{$address}", 
                self::CACHE_TTL, 
                fn() => $this->fetchWalletData($address)
            );

            return response()->json($data);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleGeneralError($e, $request->address ?? 'unknown');
        }
    }

    private function validateRequest(Request $request): void
    {
        $request->validate([
            'address' => 'required|regex:/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/'
        ]);
    }

    private function fetchWalletData(string $address): array
    {
        Log::info('Fetching wallet data for: ' . $address);
        
        $balanceXRP = $this->fetchAccountBalance($address);
        $transactions = $this->fetchTransactions($address);
        $xrpPrice = $this->fetchXRPPrice();
        $usdValue = $balanceXRP * $xrpPrice;
        
        Log::info('Wallet data compiled successfully', [
            'address' => $address,
            'balance' => $balanceXRP,
            'transactions_count' => count($transactions),
            'xrp_price' => $xrpPrice,
            'usd_value' => $usdValue
        ]);

        return compact('address', 'balanceXRP', 'transactions', 'xrpPrice', 'usdValue');
    }

    private function fetchAccountBalance(string $address): float
    {
        try {
            Log::info('Fetching account info for: ' . $address);
            
            $accountInfo = $this->sendXRPLRequest('account_info', [
                'account' => $address,
                'ledger_index' => 'validated'
            ]);
            
            if (!isset($accountInfo['account_data']['Balance'])) {
                throw new \Exception('Account balance not found in response');
            }
            
            $balanceXRP = (int) $accountInfo['account_data']['Balance'] / self::XRP_DROPS_CONVERSION;
            Log::info('Account balance found: ' . $balanceXRP . ' XRP');
            
            return $balanceXRP;
            
        } catch (\Exception $e) {
            Log::error('Account info fetch failed: ' . $e->getMessage());
            
            if ($this->isAccountNotFoundError($e)) {
                throw new \Exception('This XRP address does not exist or has never been activated');
            }
            
            throw new \Exception('Failed to fetch account information: ' . $e->getMessage());
        }
    }

    private function fetchTransactions(string $address): array
    {
        try {
            Log::info('Fetching transactions for: ' . $address);
            
            $txResult = $this->sendXRPLRequest('account_tx', [
                'account' => $address,
                'limit' => self::TRANSACTION_LIMIT,
                'ledger_index_min' => -1,
                'binary' => false
            ]);
            
            $transactions = $txResult['transactions'] ?? [];
            Log::info('Found ' . count($transactions) . ' transactions');
            
            return $transactions;
            
        } catch (\Exception $e) {
            Log::warning('Transaction fetch failed: ' . $e->getMessage());
            return []; // Don't fail completely if transactions can't be fetched
        }
    }

    private function fetchXRPPrice(): float
    {
        try {
            Log::info('Fetching XRP price from CoinGecko');
            
            $response = Http::timeout(self::PRICE_TIMEOUT)->get(self::COINGECKO_URL);
            
            if (!$response->successful()) {
                throw new \Exception('CoinGecko API request failed');
            }

            $priceData = $response->json();
            $xrpPrice = $priceData['ripple']['usd'] ?? self::FALLBACK_XRP_PRICE;
            
            Log::info('XRP price fetched: $' . $xrpPrice);
            
            return $xrpPrice;
            
        } catch (\Exception $e) {
            Log::warning('Price fetch failed: ' . $e->getMessage());
            return self::FALLBACK_XRP_PRICE;
        }
    }

    private function sendXRPLRequest(string $method, array $params): array
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)->post(self::XRPL_URL, [
                'method' => $method,
                'params' => [$params]
            ]);

            if (!$response->successful()) {
                throw new \Exception('XRPL API request failed with status: ' . $response->status());
            }

            $responseData = $response->json();
            
            if (!isset($responseData['result'])) {
                throw new \Exception('Invalid XRPL API response format');
            }

            if ($responseData['result']['status'] !== 'success') {
                $error = $responseData['result']['error'] ?? 'Unknown XRPL error';
                $errorMessage = $responseData['result']['error_message'] ?? $error;
                throw new \Exception('XRPL API error: ' . $errorMessage);
            }

            return $responseData['result'];
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('Failed to connect to XRPL network: ' . $e->getMessage());
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw new \Exception('XRPL request failed: ' . $e->getMessage());
        }
    }

    private function isAccountNotFoundError(\Exception $e): bool
    {
        return strpos($e->getMessage(), 'actNotFound') !== false || 
               strpos($e->getMessage(), 'Account not found') !== false;
    }

    private function handleValidationError(\Illuminate\Validation\ValidationException $e): JsonResponse
    {
        Log::error('Validation error', ['errors' => $e->errors()]);
        
        return response()->json([
            'message' => 'Invalid XRP address format. Address must start with "r" and be 25-34 characters long.',
            'errors' => $e->errors()
        ], 422);
    }

    private function handleGeneralError(\Exception $e, string $address): JsonResponse
    {
        Log::error('Wallet explore error: ' . $e->getMessage(), [
            'address' => $address,
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'message' => $e->getMessage()
        ], 500);
    }
}