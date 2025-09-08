<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    private $xrplUrl = 'https://s1.ripple.com:51234/';

    public function explore(Request $request): JsonResponse
    {
        try {
            Log::info('Wallet explore request received', ['address' => $request->address]);

            $request->validate([
                'address' => 'required|regex:/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/'
            ]);

            $address = $request->address;
            
            $data = Cache::remember("wallet_{$address}", 300, function () use ($address) {
                Log::info('Fetching wallet data for: ' . $address);
                
                // Helper to send XRPL JSON-RPC request
                $sendRpc = function ($method, $params) {
                    try {
                        $response = Http::timeout(15)->post($this->xrplUrl, [
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
                };

                // Fetch account info (balance)
                try {
                    Log::info('Fetching account info for: ' . $address);
                    
                    $accountInfo = $sendRpc('account_info', [
                        'account' => $address,
                        'ledger_index' => 'validated'
                    ]);
                    
                    if (!isset($accountInfo['account_data']['Balance'])) {
                        throw new \Exception('Account balance not found in response');
                    }
                    
                    $balanceXRP = (int) $accountInfo['account_data']['Balance'] / 1000000; // Drops to XRP
                    Log::info('Account balance found: ' . $balanceXRP . ' XRP');
                    
                } catch (\Exception $e) {
                    Log::error('Account info fetch failed: ' . $e->getMessage());
                    
                    // Check if it's specifically an account not found error
                    if (strpos($e->getMessage(), 'actNotFound') !== false || 
                        strpos($e->getMessage(), 'Account not found') !== false) {
                        throw new \Exception('This XRP address does not exist or has never been activated');
                    }
                    
                    throw new \Exception('Failed to fetch account information: ' . $e->getMessage());
                }

                // Fetch recent transactions (last 10)
                try {
                    Log::info('Fetching transactions for: ' . $address);
                    
                    $txResult = $sendRpc('account_tx', [
                        'account' => $address,
                        'limit' => 10,
                        'ledger_index_min' => -1,
                        'binary' => false
                    ]);
                    
                    $transactions = $txResult['transactions'] ?? [];
                    Log::info('Found ' . count($transactions) . ' transactions');
                    
                } catch (\Exception $e) {
                    Log::warning('Transaction fetch failed: ' . $e->getMessage());
                    // Don't fail completely if transactions can't be fetched
                    $transactions = [];
                }

                // Fetch XRP price from CoinGecko
                try {
                    Log::info('Fetching XRP price from CoinGecko');
                    
                    $priceResponse = Http::timeout(10)->get('https://api.coingecko.com/api/v3/simple/price?ids=ripple&vs_currencies=usd');
                    
                    if ($priceResponse->successful()) {
                        $priceData = $priceResponse->json();
                        $xrpPrice = $priceData['ripple']['usd'] ?? 0;
                        Log::info('XRP price fetched: $' . $xrpPrice);
                    } else {
                        throw new \Exception('CoinGecko API request failed');
                    }
                    
                } catch (\Exception $e) {
                    Log::warning('Price fetch failed: ' . $e->getMessage());
                    // Use a default price if CoinGecko fails
                    $xrpPrice = 0.50; // Fallback price
                }

                $usdValue = $balanceXRP * $xrpPrice;
                
                Log::info('Wallet data compiled successfully', [
                    'address' => $address,
                    'balance' => $balanceXRP,
                    'transactions_count' => count($transactions),
                    'xrp_price' => $xrpPrice,
                    'usd_value' => $usdValue
                ]);

                return compact('address', 'balanceXRP', 'transactions', 'xrpPrice', 'usdValue');
            });

            return response()->json($data);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Invalid XRP address format. Address must start with "r" and be 25-34 characters long.',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            Log::error('Wallet explore error: ' . $e->getMessage(), [
                'address' => $request->address ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }
}