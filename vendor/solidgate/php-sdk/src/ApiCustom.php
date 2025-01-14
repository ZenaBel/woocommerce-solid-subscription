<?php namespace SolidGate\API;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use SolidGate\API\DTO\MerchantData;
use Throwable;

class ApiCustom
{
    const BASE_SOLID_GATE_API_URI = 'https://pay.solidgate.com/api/v1/';
    const BASE_RECONCILIATION_API_URI = 'https://reports.solidgate.com/';

    const BASE_SOLID_SUBSCRIBE_GATE_API_URI = 'https://subscriptions.solidgate.com/api/v1/';

    const RECONCILIATION_AF_ORDER_PATH = 'api/v2/reconciliation/antifraud/order';
    const RECONCILIATION_ORDERS_PATH = 'api/v2/reconciliation/orders';
    const RECONCILIATION_CHARGEBACKS_PATH = 'api/v2/reconciliation/chargebacks';
    const RECONCILIATION_ALERTS_PATH = 'api/v2/reconciliation/chargeback-alerts';
    const RECONCILIATION_MAX_ATTEMPTS = 3;

    const FORM_PATTERN_URL = 'form?merchant=%s&form_data=%s&signature=%s';
    const RESIGN_FORM_PATTERN_URL = 'form/resign?merchant=%s&form_data=%s&signature=%s';

    protected $solidGateApiClient;
    protected $reconciliationsApiClient;

    protected $merchantId;
    protected $privateKey;
    protected $exception;
    protected $formUrlPattern;
    private $resignFormUrlPattern;

    public function __construct(
        string $merchantId,
        string $privateKey,
        string $baseSolidGateApiUri = self::BASE_SOLID_GATE_API_URI,
        string $baseReconciliationsApiUri = self::BASE_RECONCILIATION_API_URI,
        string $baseSolidSubscribeGateApiUri = self::BASE_SOLID_SUBSCRIBE_GATE_API_URI
    ) {
        $this->merchantId = $merchantId;
        $this->privateKey = $privateKey;
        $this->formUrlPattern = $baseSolidGateApiUri . self::FORM_PATTERN_URL;
        $this->resignFormUrlPattern = $baseSolidGateApiUri . self::RESIGN_FORM_PATTERN_URL;

        $this->solidGateApiClient = new HttpClient(
            [
                'base_uri' => $baseSolidGateApiUri,
                'verify'   => true,
            ]
        );

        $this->reconciliationsApiClient = new HttpClient(
            [
                'base_uri' => $baseReconciliationsApiUri,
                'verify'   => true,
            ]
        );

        $this->solidSubscribeGateApiClient = new HttpClient(
            [
                'base_uri' => $baseSolidSubscribeGateApiUri,
                'verify'   => true,
            ]
        );
    }

    public function addProduct(array $attributes): string
    {
        return $this->sendRequestPOST('products', $attributes);
    }

    public function getProducts(array $attributes = []): string
    {
        $path = 'products?' . http_build_query($attributes);

        return $this->sendRequestGET($path, []);
    }

    public function updateProduct(string $productId, array $attributes): string
    {
        return $this->sendRequestPATCH('products/' . $productId, $attributes);
    }

    public function addPrice(string $productId, array $attributes): string
    {
        return $this->sendRequestPOST('products/' . $productId . '/prices', $attributes);
    }

    public function getPrices(string $productId, array $attributes = []): string
    {
        $path = 'products/' . $productId . '/prices?' . http_build_query($attributes);

        return $this->sendRequestGET($path, []);
    }

    public function updatePrice(string $productId, string $priceId, array $attributes): string
    {
        return $this->sendRequestPATCH('products/' . $productId . '/prices/' . $priceId, $attributes);
    }

    public function cancelSubscription(array $attributes): string
    {
        return $this->sendRequestPOST('subscription/cancel', $attributes);
    }

    public function getSubscriptionStatus(array $attributes): string
    {
        return $this->sendRequestPOST('subscription/status', $attributes);
    }

    public function pauseSchedule(string $subscription_id, array $attributes): string
    {
        return $this->sendRequestPOST("subscriptions/$subscription_id/pause-schedule", $attributes);
    }

    public function updatePauseSchedule(string $subscription_id, array $attributes): string
    {
        return $this->sendRequestPATCH("subscriptions/$subscription_id/pause-schedule", $attributes);
    }

    public function removePauseSchedule(string $subscription_id): string
    {
        return $this->sendRequestDELETE("subscriptions/$subscription_id/pause-schedule");
    }

    public function getProduct($product_uuid): string
    {
        return $this->sendRequestGET('products/' . $product_uuid, []);
    }

    public function switchProductSubscription(array $data): string
    {
        return $this->sendRequestPOST('subscription/switch-subscription-product', $data);
    }

    public function createPrice($product_uuid, array $data): string
    {
        return $this->sendRequestPOST("products/$product_uuid/prices", $data);
    }

    public function getProductPrices($uuid): string
    {
        return $this->sendRequestGET("products/$uuid/prices", []);
    }

    public function calculatePrice(array $data): string
    {
        return $this->sendRequestPOST('products/calculatePrice', $data);
    }

    public function reactivateSubscription(array $attributes): string
    {
        return $this->sendRequestPOST("subscription/restore", $attributes);
    }

    public function charge(array $attributes): string
    {
        return $this->sendRequest('charge', $attributes);
    }

    public function recurring(array $attributes): string
    {
        return $this->sendRequest('recurring', $attributes);
    }

    public function status(array $attributes): string
    {
        return $this->sendRequest('status', $attributes);
    }

    public function refund(array $attributes): string
    {
        return $this->sendRequest('refund', $attributes);
    }

    public function initPayment(array $attributes): string
    {
        return $this->sendRequest('init-payment', $attributes);
    }

    public function resign(array $attributes): string
    {
        return $this->sendRequest('resign', $attributes);
    }

    public function auth(array $attributes): string
    {
        return $this->sendRequest('auth', $attributes);
    }

    public function void(array $attributes): string
    {
        return $this->sendRequest('void', $attributes);
    }

    public function settle(array $attributes): string
    {
        return $this->sendRequest('settle', $attributes);
    }

    public function arnCode(array $attributes): string
    {
        return $this->sendRequest('arn-code', $attributes);
    }

    public function applePay(array $attributes): string
    {
        return $this->sendRequest('apple-pay', $attributes);
    }

    public function googlePay(array $attributes): string
    {
        return $this->sendRequest('google-pay', $attributes);
    }

    public function formUrl(array $attributes): string
    {
        $encryptedFormData = $this->generateEncryptedFormData($attributes);
        $signature = $this->generateSignature($encryptedFormData);

        return sprintf($this->formUrlPattern, $this->getMerchantId(), $encryptedFormData, $signature);
    }

    public function resignFormUrl(array $attributes): string
    {
        $encryptedFormData = $this->generateEncryptedFormData($attributes);
        $signature = $this->generateSignature($encryptedFormData);

        return sprintf($this->resignFormUrlPattern, $this->getMerchantId(), $encryptedFormData, $signature);
    }

    public function formMerchantData(array $attributes): MerchantData
    {
        $encryptedFormData = $this->generateEncryptedFormData($attributes);
        $signature = $this->generateSignature($encryptedFormData);

        return new MerchantData($encryptedFormData, $this->getMerchantId(), $signature);
    }

    public function getUpdatedOrders(
        \DateTime $dateFrom,
        \DateTime $dateTo,
        int $maxAttempts = self::RECONCILIATION_MAX_ATTEMPTS
    ): \Generator {
        return $this->sendReconciliationsRequest($dateFrom, $dateTo, self::RECONCILIATION_ORDERS_PATH, $maxAttempts);
    }

    public function getUpdatedChargebacks(
        \DateTime $dateFrom,
        \DateTime $dateTo,
        int $maxAttempts = self::RECONCILIATION_MAX_ATTEMPTS
    ): \Generator {
        return $this->sendReconciliationsRequest($dateFrom, $dateTo, self::RECONCILIATION_CHARGEBACKS_PATH,
            $maxAttempts);
    }

    public function getUpdatedAlerts(
        \DateTime $dateFrom,
        \DateTime $dateTo,
        int $maxAttempts = self::RECONCILIATION_MAX_ATTEMPTS
    ): \Generator {
        return $this->sendReconciliationsRequest($dateFrom, $dateTo, self::RECONCILIATION_ALERTS_PATH, $maxAttempts);
    }

    public function getAntifraudOrderInformation(string $orderId): string
    {
        $request = $this->makeRequest(self::RECONCILIATION_AF_ORDER_PATH, [
            'order_id' => $orderId,
        ]);

        try {
            $response = $this->reconciliationsApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function getMerchantId(): ?string
    {
        return $this->merchantId;
    }

    public function getPrivateKey(): ?string
    {
        return $this->privateKey;
    }

    public function generateSignature(string $data): string
    {
        return base64_encode(
            hash_hmac('sha512',
                $this->getMerchantId() . $data . $this->getMerchantId(),
                $this->getPrivateKey())
        );
    }

    public function sendRequest(string $method, array $attributes): string
    {
        $request = $this->makeRequest($method, $attributes);

        try {
            $response = $this->solidGateApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function sendRequestPOST(string $method, array $attributes): string
    {
        $request = $this->makeRequest($method, $attributes);

        try {
            $response = $this->solidSubscribeGateApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function sendRequestGET(string $method, array $attributes): string
    {
        $request = $this->makeRequestGET($method, $attributes);

        try {
            $response = $this->solidSubscribeGateApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function sendRequestPATCH(string $string, array $attributes): string
    {
        $request = $this->makeRequestPATCH($string, $attributes);

        try {
            $response = $this->solidSubscribeGateApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function sendRequestDELETE(string $string, array $attributes = []): string
    {
        $request = $this->makeRequestDELETE($string, $attributes);

        try {
            $response = $this->solidSubscribeGateApiClient->send($request);

            return $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->exception = $e;
        }

        return '';
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    protected function base64UrlEncode(string $data): string
    {
        $urlEncoded = strtr(base64_encode($data), '+/', '-_');

        return $urlEncoded;
    }

    public function sendReconciliationsRequest(
        \DateTime $dateFrom,
        \DateTime $dateTo,
        string $url,
        int $maxAttempts
    ): \Generator {
        $nextPageIterator = null;
        do {
            $attributes = [
                'date_from' => $dateFrom->format('Y-m-d H:i:s'),
                'date_to'   => $dateTo->format('Y-m-d H:i:s'),
            ];

            if ($nextPageIterator) {
                $attributes['next_page_iterator'] = $nextPageIterator;
            }

            $request = $this->makeRequest($url, $attributes);
            try {
                $responseArray = $this->sendReconciliationsRequestInternal($request, $maxAttempts);
                $nextPageIterator = ($responseArray['metadata'] ?? [])['next_page_iterator'] ?? null;

                foreach ($responseArray['orders'] as $order) {
                    yield $order;
                }
            } catch (Throwable $e) {
                $this->exception = $e;

                return;
            }
        } while ($nextPageIterator != null);
    }

    private function sendReconciliationsRequestInternal(Request $request, int $maxAttempts): array
    {
        $attempt = 0;
        $lastException = null;
        while ($attempt < $maxAttempts) {
            $attempt += 1;
            try {
                $response = $this->reconciliationsApiClient->send($request);
                $responseArray = json_decode($response->getBody()->getContents(), true);
                if (is_array($responseArray) && isset($responseArray['orders']) && is_array($responseArray['orders'])) {
                    return $responseArray;
                }
                $lastException = new \RuntimeException("Incorrect response structure. Need retry request");
            } catch (Throwable $e) {
                $lastException = $e;
            }
        }

        throw new $lastException;
    }

    protected function generateEncryptedFormData(array $attributes): string
    {
        $attributes = json_encode($attributes);
        $secretKey = substr($this->getPrivateKey(), 0, 32);

        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivLen);

        $encrypt = openssl_encrypt($attributes, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

        return $this->base64UrlEncode($iv . $encrypt);
    }

    protected function makeRequest(string $path, array $attributes): Request
    {
        $body = json_encode($attributes);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Merchant'     => $this->getMerchantId(),
            'Signature'    => $this->generateSignature($body),
        ];

        return new Request('POST', $path, $headers, $body);
    }

    protected function makeRequestGET(string $path, array $attributes): Request
    {
        $body = json_encode($attributes);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Merchant'     => $this->getMerchantId(),
            'Signature'    => $this->generateSignature($body),
        ];

        return new Request('GET', $path, $headers, $body);
    }

    protected function makeRequestPATCH(string $path, array $attributes): Request
    {
        $body = json_encode($attributes);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Merchant'     => $this->getMerchantId(),
            'Signature'    => $this->generateSignature($body),
        ];

        return new Request('PATCH', $path, $headers, $body);
    }

    protected function makeRequestDELETE(string $path, array $attributes): Request
    {
        $body = json_encode($attributes);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Merchant'     => $this->getMerchantId(),
            'Signature'    => $this->generateSignature($body),
        ];

        return new Request('DELETE', $path, $headers, $body);
    }
}
