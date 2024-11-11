<?php

class MobbexApi
{
    public $ready = false;

    /** Mobbex API base URL */
    public $api_url = 'https://api.mobbex.com/p/';

    /** Commerce API Key */
    private $api_key;

    /** Commerce Access Token */
    private $access_token;

    /**
     * Constructor.
     * 
     * Set Mobbex credentails.
     * 
     * @param string $api_key Commerce API Key.
     * @param string $access_token Commerce Access Token.
     */
    public function __construct($api_key, $access_token)
    {
        // TODO: Maybe this could recieve a mobbex store object
        $this->api_key      = $api_key;
        $this->access_token = $access_token;
        $this->ready        = !empty($api_key) && !empty($access_token);
    }

    /**
     * Make a request to Mobbex API.
     * 
     * @param array $data 
     * 
     * @return mixed Result status or data if exists.
     * 
     * @throws \Exception
     */
    public function request($data)
    {
        if (!$this->ready)
            return false;

        if (empty($data['method']) || empty($data['uri']))
            throw new \Exception('Mobbex request error: Missing arguments'. "data: $data", 0);

        mbbxs_log('debug', 'Api > Request | Request Data:', $data);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->api_url . $data['uri'] . (!empty($data['params']) ? '?' . http_build_query($data['params']) : null),
            CURLOPT_HTTPHEADER     => $this->get_headers(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => $data['method'],
            CURLOPT_POSTFIELDS     => !empty($data['body']) ? json_encode($data['body']) : null,
        ]);

        $response    = curl_exec($curl);
        $error       = curl_error($curl);
        $errorNumber = curl_errno($curl);

        curl_close($curl);

        // Throw curl errors
        if ($error)
            throw new \Exception('Curl error in Mobbex request #:' . $error . json_encode($data), $errorNumber);

        $result = json_decode($response, true);

        // Throw request errors
        if (!$result)
            throw new \Exception('Mobbex request error: Invalid response format. Data: '. json_encode($data), 0);

        // Return raw response if requested
        if (!empty($data['raw']))
            return $result;

        if (!$result['result'])
            throw new \Exception(sprintf(
                'Mobbex request error #%s: %s %s. Data: %s',
                isset($result['code']) ? $result['code'] : 'NOCODE',
                isset($result['error']) ? $result['error'] : 'NOERROR',
                isset($result['status_message']) ? $result['status_message'] : 'NOMESSAGE',
                json_encode($data)
            ), 0);

        return isset($result['data']) ? $result['data'] : $result['result'];
    }

    /**
     * Get headers to connect with Mobbex API.
     * 
     * @return string[] 
     */
    private function get_headers()
    {
        return [
            'cache-control: no-cache',
            'content-type: application/json',
            'x-api-key: ' . $this->api_key,
            'x-access-token: ' . $this->access_token,
            'x-ecommerce-agent: WordPress/' . get_bloginfo('version') . ' WooCommerce/' . WC_VERSION . ' Plugin/' . MOBBEX_SUBS_VERSION,
        ];
    }
}