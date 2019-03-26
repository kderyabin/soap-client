<?php
/**
 * Copyright (c) 2018 Konstantin Deryabin
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Kod;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SoapHttpClient
 */
class SoapHttpClient extends \SoapClient
{
    /**
     * @var null|RequestInterface
     */
    protected $request;
    /**
     * @var null|ResponseInterface
     */
    protected $response;
    /**
     * Options array as they are passed to SoapHttpClient.
     * @var array
     */
    protected $options = [];
    /**
     * @var ClientInterface
     */
    protected $client;
    /**
     * Client options
     * @var array
     */
    protected $clientOptions = [];

    /**
     * @var null|string
     */
    protected $__last_request_headers;

    /**
     * @string
     */
    protected $__last_response_headers;

    /**
     * SoapHttpClient constructor.
     * @param string $wsdl configuration file.
     * @param array|null $options The mix of native \SoapClient options and Guzzle client options.
     */
    public function __construct($wsdl, array $options = null)
    {
        parent::__construct($wsdl, $options);
        $this->options = $options;
        $this->clientOptions = $this->buildClientOption($this->getClientSpecific());
    }

    /**
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @param ClientInterface $client
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Get Guzzle client options.
     * Some native options of a SoapClient are transformed into Guzzle options.
     *
     * @return array
     */
    public function getClientSpecific(): array
    {
        // commented options are transformed into Guzzle options
        // uncommented options are not used in Guzzle
        $nativeOptions = [
//            'authentication',
//            'login',
//            'password',
//            'proxy_host',
//            'proxy_port',
//            'proxy_login',
//            'proxy_password',
//            'connection_timeout',
//            'keep_alive',
//            'user_agent',
//            'local_cert',
//            'passphrase',
            'compression',
            'location',
            'uri',
            'soap_version',
            'encoding',
            'trace',
            'classmap',
            'exceptions',
            'typemap',
            'cache_wsdl',
            'stream_context',
            'features',
            'ssl_method'
        ];
        $assoc = array_combine($nativeOptions, $nativeOptions);

        return array_diff_key($this->options, $assoc);
    }

    /**
     * @param array $result
     * @return array
     */
    public function buildClientOption(array $result): array
    {
        $options = [];
        // Authentication
        if (isset($result['authentication'])) {
            $options['auth'] = [$result['login'], $result['password'] ?? ''];
            if($result['authentication'] === \SOAP_AUTHENTICATION_DIGEST) {
                $options['auth'][] = 'digest';
            }

            unset($result['authentication'], $result['login'], $result['password']);
        }
        // Certificate
        if (isset($result['local_cert']) && isset($result['passphrase'])) {
            $options['cert'] = [
                $result['local_cert'],
                $result['passphrase'],
            ];
            unset($result['local_cert'], $result['passphrase']);
        }
        // Proxy
        if (!empty($result['proxy_host'])) {
            if (!empty($result['proxy_login']) && isset($result['proxy_password'])) {
                // need to parse the host and append user:password
                $parts = parse_url($result['proxy_host']);
                $result['proxy_host'] = sprintf(
                    '%s%s:%s@%s',
                    isset($parts['scheme']) ? $parts['scheme'] . '://' : '',
                    $result['proxy_login'],
                    isset($result['proxy_password']) ? $result['proxy_password'] : '',
                    $parts['host']
                );
                unset($result['proxy_login'], $result['proxy_password']);
            }
            $options['proxy'] = $result['proxy_host'] . ($result['proxy_port'] ? ':' . $result['proxy_port'] : '');
            unset($result['proxy_host'], $result['proxy_port']);
        }

        if (isset($result['user_agent'])) {
            if (empty($options['headers'])) {
                $options['headers'] = [];
            }
            $options['headers']['User-Agent'] = $result['user_agent'];
            unset($result['user_agent']);
        }

        if (!empty($result['keep_alive'])) {
            if (empty($options['headers'])) {
                $options['headers'] = [];
            }
            $options['headers']['Connection'] = $result['Keep-Alive'];
            unset($result['keep_alive']);
        }

        if (isset($result['connection_timeout'])) {
            $options['connect_timeout'] = $result['connection_timeout'];
            unset($result['connection_timeout']);
        }
        // Left options in $result are clients native options or do not require transformation
        $options += $result;

        return $options;
    }

    /**
     * @param string $request
     * @param string $location
     * @param string $action
     * @param int $version
     * @param int $one_way
     * @return null|\SoapFault|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \SoapFault
     */
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $error = null;
        $headers = [
            'Content-Type' => $version == 2 ? 'application/soap+xml' : 'text/xml',
            'Content-Length' => strlen($request),
        ];
        if (!empty($this->options['encoding'])) {
            $headers['Content-Type'] .= ';charset=' . $this->options['encoding'];
        }
        if ($action) {
            $headers['SOAPAction'] = $action;
        }
        $this->request = new Request('POST', $location, $headers, $request);
        if (!empty($this->options['trace'])) {
            $this->__last_request_headers = $this->getHeaders($this->request);
        }

        try {
            $client = $this->getClient();
            $this->response = $client->send($this->request, $this->clientOptions);

            if (!empty($this->options['trace'])) {
                $this->__last_response_headers = $this->getHeaders($this->response);
            }
            $result = (string)$this->response->getBody();
            if (!$one_way) {
                return $result;
            }
        } catch (\Throwable $ex) {
            $error = new \SoapFault((string)$ex->getCode(), $ex->getMessage());
            if ($this->options['exceptions'] === true) {
                throw $error;
            }
            return $error;
        }
    }

    /**
     * @return null|RequestInterface
     */
    public function getRequest(): ?RequestInterface
    {
        return $this->request;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * @param MessageInterface $message
     * @return string
     */
    protected function getHeaders(MessageInterface $message): string
    {
        $msg = '';
        if ($message instanceof RequestInterface) {
            $msg = trim($message->getMethod() . ' '
                    . $message->getRequestTarget())
                . ' HTTP/' . $message->getProtocolVersion();
            if (!$message->hasHeader('host')) {
                $msg .= "\r\nHost: " . $message->getUri()->getHost();
            }
        } elseif ($message instanceof ResponseInterface) {
            $msg = 'HTTP/' . $message->getProtocolVersion() . ' '
                . $message->getStatusCode() . ' '
                . $message->getReasonPhrase();
        } else {
            return $msg;
        }

        foreach ($message->getHeaders() as $name => $values) {
            $msg .= "\r\n{$name}: " . implode(', ', $values);
        }

        return $msg;
    }
}
