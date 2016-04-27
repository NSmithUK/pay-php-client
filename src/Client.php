<?php
namespace Alphagov\Pay;

use GuzzleHttp\Psr7\Uri;                            // Concrete PSR-7 URL representation.
use GuzzleHttp\Psr7\Request;                        // Concrete PSR-7 HTTP Request
use Psr\Http\Message\UriInterface;                  // PSR-7 URI Interface
use Psr\Http\Message\ResponseInterface;             // PSR-7 HTTP Response Interface
use Http\Client\HttpClient as HttpClientInterface;  // Interface for a PSR-7 compatible HTTP Client.

/**
 * Client for accessing GOV.UK Pay.
 *
 * Before using this client you must have:
 *  - created an account with GOV.UK Pay
 *  - A valid API key.
 *
 * Class Client
 * @package Alphagov\Pay
 */
class Client {

    /**
     * @const string Current version of this client.
     * This follows Semantic Versioning (http://semver.org/)
     */
    const VERSION = '0.1.0';

    /**
     * @const string The API endpoint for Notify production.
     */
    const BASE_URL_PRODUCTION = 'https://publicapi.pymnt.uk';

    /**
     * Paths for API endpoints.
     */
    const PATH_PAYMENT_LIST     = '/v1/payments';
    const PATH_PAYMENT_CREATE   = '/v1/payments';
    const PATH_PAYMENT_LOOKUP   = '/v1/payments/%s';
    const PATH_PAYMENT_EVENTS   = '/v1/payments/%s/events';
    const PATH_PAYMENT_CANCEL   = '/v1/payments/%s/cancel';


    /**
     * @var string base scheme and hostname
     */
    protected $baseUrl;

    /**
     * @var HttpClientInterface PSR-7 compatible HTTP Client
     */
    private $httpClient;

    /**
     * @var string
     */
    private $apiKey;
    

    public function __construct( array $config )
    {

        $config = array_merge([
            'httpClient' => null,
            'apiKey' => null,
            'baseUrl' => null,
        ], $config);


        //--------------------------
        // Set base URL

        if( !isset( $config['baseUrl'] ) ){

            // If not set, we default to production
            $this->baseUrl = self::BASE_URL_PRODUCTION;

        } elseif ( filter_var($config['baseUrl'], FILTER_VALIDATE_URL) !== false ) {

            // Else we allow an arbitrary URL to be set.
            $this->baseUrl = $config['baseUrl'];

        } else {

            throw new Exception\InvalidArgumentException(
                "Invalid 'baseUrl' set. This must be either a valid URL, or null."
            );

        }

        //--------------------------
        // Set HTTP Client

        if( $config['httpClient'] instanceof HttpClientInterface ){

            $this->setHttpClient( $config['httpClient'] );

        } else {

            throw new Exception\InvalidArgumentException(
                "An instance of HttpClientInterface must be set under 'httpClient'"
            );

        }

        //--------------------------
        // Set API Key

        if( is_string($config['apiKey']) ){

            $this->apiKey = $config['apiKey'];

        } else {

            throw new Exception\InvalidArgumentException(
                "'apiKey' must be set"
            );

        }

        
    }

    //------------------------------------------------------------------------------------
    // Public API access methods

    public function createPayment( $amount, $reference, $description, UriInterface $returnUrl ){

        if( !is_int($amount) ){
            throw new Exception\InvalidArgumentException(
                '$amount must be an integer, representing the amount, in pence'
            );
        }

        $response = $this->httpPost( self::PATH_PAYMENT_CREATE, [
            'amount'        => (int)$amount,
            'reference'     => (string)$reference,
            'description'   => (string)$description,
            'return_url'    => (string)$returnUrl,
        ]);

        return ( is_array($response) ) ? new Response\Payment($response) : $response;
        
    }

    public function getPayment( $paymentId ){

        $path = sprintf( self::PATH_PAYMENT_LOOKUP, $paymentId );

        $response = $this->httpGet( $path );

        return ( is_array($response) ) ? new Response\Payment($response) : null;

    }

    public function getPaymentEvents( $paymentId ){

        $path = sprintf( self::PATH_PAYMENT_EVENTS, $paymentId );

        $response =  $this->httpGet( $path );

        // Ensure we have an array of events
        if( !isset($response['events']) || !is_array($response['events']) ){
            return array();
        }

        // Map arrays to Event objects
        return array_map( function($event){
            return new Response\Event( $event );
        }, $response['events']);

    }

    public function cancelPayment( $paymentId ){

        $path = sprintf( self::PATH_PAYMENT_CANCEL, $paymentId );

        return $this->httpPost( $path );

    }

    public function searchPayments( array $filters = array() ){

        // Only allow the following filter keys.
        $filters = array_intersect_key( $filters, array_flip([
            'reference',
            'status',
            'from_date',
            'to_date',
        ]));

        return $this->httpGet( self::PATH_PAYMENT_LIST, $filters );
        
    }

    //------------------------------------------------------------------------------------
    // Internal API access methods


    /**
     * Generates the standard set of HTTP headers expected by the API.
     *
     * @return array
     */
    private function buildHeaders(){

        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept'        => 'application/json',
            'Content-type'  => 'application/json',
            'User-agent'    => 'PAY-API-PHP-CLIENT/'.self::VERSION
        ];

    }

    //-------------------------------------------
    // GET & POST requests

    /**
     * Performs a GET against the Pay API.
     *
     * @param string $path
     * @param array  $query
     *
     * @return array|null
     * @throw Exception\PayException | Exception\ApiException | Exception\UnexpectedValueException
     */
    private function httpGet( $path, array $query = array() ){

        $url = new Uri( $this->baseUrl . $path );

        foreach( $query as $name => $value ){
            $url = URI::withQueryValue($url, $name, $value );
        }

        //---

        $request = new Request(
            'GET',
            $url,
            $this->buildHeaders()
        );

        try {

            $response = $this->getHttpClient()->sendRequest( $request );

        } catch (\RuntimeException $e){
            throw new Exception\PayException( $e->getMessage(), $e->getCode(), $e );
        }

        switch( $response->getStatusCode() ){
            case 200:
                return $this->handleResponse( $response );
            case 404:
                return null;
            default:
                return $this->handleErrorResponse( $response );
        }

    }


    /**
     * Performs a POST against the Pay API.
     *
     * @param string $path
     * @param array  $payload
     *
     * @return array
     * @throw Exception\PayException | Exception\ApiException | Exception\UnexpectedValueException
     */
    private function httpPost( $path, array $payload = array() ){

        $url = new Uri( $this->baseUrl . $path );

        $request = new Request(
            'POST',
            $url,
            $this->buildHeaders(),
            ( !empty($payload) ) ? json_encode($payload) : null
        );

        try {

            $response = $this->getHttpClient()->sendRequest( $request );

        } catch (\RuntimeException $e){
            throw new Exception\PayException( $e->getMessage(), $e->getCode(), $e );
        }

        switch( $response->getStatusCode() ){
            case 201:
                return $this->handleResponse( $response );
            case 204:
                return true;
            default:
                return $this->handleErrorResponse( $response );
        }

    }

    //-------------------------------------------
    // Response Handling

    /**
     * Called with a response from the API when the response code was successful. i.e. 20X.
     *
     * @param ResponseInterface $response
     *
     * @return array
     * @throw Exception\ApiException
     */
    protected function handleResponse( ResponseInterface $response ){

        $body = json_decode($response->getBody(), true);

        // The expected response should always be JSON, thus now an array.
        if( !is_array($body) ){
            throw new Exception\ApiException( 'Malformed JSON response from server', $response->getStatusCode(), $response );
        }

        return $body;

    }

    /**
     * Called with a response from the API when the response code was unsuccessful. i.e. not 20X.
     *
     * @param ResponseInterface $response
     *
     * @return null
     * @throw Exception\ApiException
     */
    protected function handleErrorResponse( ResponseInterface $response ){

        $body = json_decode($response->getBody(), true);

        $message = "HTTP:{$response->getStatusCode()} - ";
        $message .= (is_array($body)) ? print_r($body, true) : 'Unexpected response from server';

        throw new Exception\ApiException( $message, $response->getStatusCode(), $response );

    }

    //------------------------------------------------------------------------------------
    // Getters and setters

    /**
     * @return HttpClientInterface
     * @throws Exception\UnexpectedValueException
     */
    final protected function getHttpClient(){

        if( !( $this->httpClient instanceof HttpClientInterface ) ){
            throw new Exception\UnexpectedValueException('Invalid HttpClient set');
        }

        return $this->httpClient;

    }

    /**
     * @param HttpClientInterface $client
     */
    final protected function setHttpClient( HttpClientInterface $client ){

        $this->httpClient = $client;

    }


}
