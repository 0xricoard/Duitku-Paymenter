<?php



namespace App\Extensions\Gateways\Duitku;



use App\Classes\Extensions\Gateway;

use App\Helpers\ExtensionHelper;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Http;

use Illuminate\Http\Request;



class Duitku extends Gateway

{

    /**

     * Get the extension metadata

     * 

     * @return array

     */

    public function getMetadata()

    {

        return [

            'display_name' => 'Duitku',

            'version'      => '1.0.0',

            'author'       => '0xricoard',

            'website'      => 'https://servermikro.com',

        ];

    }



    /**

     * Get all the configuration for the extension

     * 

     * @return array

     */

    public function getConfig()

    {

        return [

            [

                'name'         => 'merchant_code',

                'friendlyName' => 'Merchant Code',

                'type'        => 'text',

                'required'    => true,

            ],

            [

                'name'         => 'api_key',

                'friendlyName' => 'API Key',

                'type'        => 'text',

                'required'    => true,

            ],

            [

                'name'         => 'callback_url',

                'friendlyName' => 'Callback URL',

                'type'        => 'text',

                'required'    => true,

            ],

            [

                'name'         => 'environment',

                'friendlyName' => 'Environment (sandbox/production)',

                'type'        => 'text',

                'required'    => true,

            ],

        ];

    }



    /**

     * Get the URL to redirect to

     * 

     * @param int $total

     * @param array $products

     * @param int $orderId

     * @return string|false

     */

    public function pay($total, $products, $orderId)

    {

        $environment = ExtensionHelper::getConfig('Duitku', 'environment');

        $url = 'https://api-sandbox.duitku.com/api/merchant/createInvoice';

        if ($environment === 'production') {

            $url = 'https://api-prod.duitku.com/api/merchant/createInvoice';

        }



        $merchantCode = ExtensionHelper::getConfig('Duitku', 'merchant_code');

        $apiKey = ExtensionHelper::getConfig('Duitku', 'api_key');

        $callbackUrl = ExtensionHelper::getConfig('Duitku', 'callback_url');

        $returnUrl = route('clients.invoice.show', $orderId);



        $description = 'Products: ';

        foreach ($products as $product) {

            $description .= $product->name . ' x' . $product->quantity . ', ';

        }

        $description = rtrim($description, ', '); // Menghapus koma terakhir



        // Generate timestamp in milliseconds

        $timestamp = round(microtime(true) * 1000);



        // Generate signature

        $signature = hash('sha256', $merchantCode . $timestamp . $apiKey);



        $params = [

            'merchantCode' => $merchantCode,

            'paymentAmount' => (int) $total, // Pastikan paymentAmount adalah integer

            'merchantOrderId' => (string) $orderId, // Pastikan merchantOrderId adalah string

            'productDetails' => $description,

            //'additionalParam' => '',

            'merchantUserInfo' => auth()->user()->email,

            'email' => auth()->user()->email,

            'callbackUrl' => $callbackUrl,

            'returnUrl' => $returnUrl,

            'signature' => $signature,

        ];



        // Set headers

        $headers = [

            'Accept' => 'application/json',

            'Content-Type' => 'application/json',

            'x-duitku-signature' => $signature,

            'x-duitku-timestamp' => $timestamp,

            'x-duitku-merchantcode' => $merchantCode,

        ];



        // Send request to Duitku API

        $response = Http::withHeaders($headers)->post($url, $params);



        // Check if the response is successful

        if ($response->successful()) {

            $responseData = $response->json();

            if ($responseData['statusCode'] == '00') {

                return $responseData['paymentUrl']; // Mengembalikan URL pembayaran

            } else {

                Log::error('Duitku Payment Error', ['response' => $responseData]);

                return false;

            }

        } else {

            Log::error('Duitku Payment Error', ['response' => $response->body()]);

            return false;

        }

    }



    public function webhook(Request $request)

    {

        $apiKey = ExtensionHelper::getConfig('Duitku', 'api_key');



        // Mengambil data POST dari request

        $merchantCode = $request->input('merchantCode');

        $amount = $request->input('amount');

        $merchantOrderId = $request->input('merchantOrderId');

        $signature = $request->input('signature');

        $resultCode = $request->input('resultCode');



        // Log data untuk debug

        Log::debug('Duitku Webhook Data', $request->all());



        // Pastikan semua parameter diterima dengan benar

        if (!$merchantCode || !$amount || !$merchantOrderId || !$signature) {

            Log::error('Missing parameters', $request->all());

            return response()->json(['success' => false, 'message' => 'Missing parameters'], 400);

        }



        // Hitung tanda tangan yang benar

        $calculatedSignature = md5($merchantCode . $amount . $merchantOrderId . $apiKey);



        // Log data untuk debug

        Log::debug('Duitku Webhook Signature Verification', [

            'received_signature' => $signature,

            'calculated_signature' => $calculatedSignature,

        ]);



        if ($signature !== $calculatedSignature) {

            Log::error('Invalid signature', [

                'received_signature' => $signature,

                'calculated_signature' => $calculatedSignature,

            ]);

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);

        }



        if ($resultCode === '00') { // '00' code for successful payment

            ExtensionHelper::paymentDone($merchantOrderId, 'Duitku');

            return response()->json(['success' => true]);

        } elseif (in_array($resultCode, ['01', '02'])) { // Example: '01' for expired, '02' for failed

            return response()->json(['success' => true]);

        } else {

            return response()->json(['success' => false, 'message' => 'Invalid status'], 400);

        }

    }

}
