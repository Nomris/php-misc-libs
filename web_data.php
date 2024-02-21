<?php

final class RequestData
{
    /**
     * The HTTP-Method of this request
     * eg. GET, POST
     */
    public readonly string $Method;

    /**
     * The complete URL of this request
     * eg. http://example.com/admin?user=admin&ref_id=32543
     */
    public readonly string $RawUri;

    /**
     * The used Protocol / Schema of this request
     * eg. HTTP, HTTPS
     */
    public readonly string $Schema;

    /**
     * The Hostname of this request
     * eg. localhost, 127.0.0.1, example.com
     */
    public readonly string $Host;

    /**
     * @var array<string>
     * Each element of the URLs path component
     * eg. /admin/user-managment => [admin, user-managment]
     */
    public $Path;

    /**
     * @var array<string>
     * Each element of the URLs query componet
     * eg. ?user=admin&ref_id=32434&show_name => [user => admin, ref_id => 32434, show_name => true]
     */
    public readonly array $Query;

    
    /**
     * @var array<string>
     * Each HTTP-Header from the initial request
     * eg: Accept-Encoding, Host, Cache-Control
     */
    public readonly array $Headers;

    /**
     * The IP Address and Port of the Client of the requesting 
     */
    public readonly string $RemoteEndPoint;

    /**
     * The IP Address and Port that was locally used to accept the request
     */
    public readonly string $LocalEndPoint;

    /**
     * The IP Address and Port of the proxy server that was used, may be null if no proxy server was detected
     */
    public readonly string|null $ProxyEndPoint;

    /**
     * The Type of the Content, null if there isn't any
     */
    public readonly string|null $ContentType;

    /**
     * The Content, null if there isn't any
     * Type may differ based on Content-Type
     */
    public readonly mixed $Content;

    /**
     * @param array $server Override default of using $_SERVER (The array keys must match)
     * @param mixed $contentOverride Override getting Content from globals or php://input
     */
    public function __construct(array $server = null, mixed $contentOverride = null)
    {
        if ($server === null) $server = $_SERVER;
        $this->Method = strtolower($server['REQUEST_METHOD']); // Getting HTTP-Method
        $this->Schema = strtolower($server['REQUEST_SCHEME']); // Getting HTTP-Protocol
        $this->Host = $server['HTTP_HOST']; // Getting Http-Hostname
        $url = urldecode($server['REQUEST_URI']); // Getting and decoding URL
        
        $this->RawUri = $this->Schema . '://' . $this->Host . $url; // Construct RawURI

        // Trim first slash from url
        if ($url[0] == '/') $url = substr($url, 1);

        $segments = explode('?', $url, 2); // Seperate URL into PATH(0) and QUERY(1)

        $path = explode('/', $segments[0]);
        $lastElem = count($path) == 0 ? null : max(0, count($path) - 1);
        if (trim($path[$lastElem]) === '') unset($path[$lastElem]); // If the last path element ist empty, remove it
        $this->Path = $path;

        $query = array();
        if (count($segments) >= 2)
        {
            $rawQuery = explode('&', $segments[1]);
            
            $i = count($rawQuery) - 1;
            while ($i >= 0)
            {
                $querySegements = explode('=', $rawQuery[$i], 2);
                if (count($querySegements) == 1) $querySegements[1] = true; // Check for value, if none then set true
                $query[$querySegements[0]] = $querySegements[1];
                $i--;
            }
        }
        $this->Query = $query;

        // Iterate and assign all HTTP-Headers
        $headers = array();
        $serverKeys = array_keys($server);
        $i = count ($serverKeys) - 1;
        while ($i >= 0)
        {
            if (str_starts_with($serverKeys[$i], 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($serverKeys[$i], 5)))] = $server[$serverKeys[$i]];
            $i--;
        }

        // Process and potently parse Content
        $contentType = null;
        $content = $contentOverride;
        if (isset($server['CONTENT_TYPE'])) 
        {
            $headers['content-type'] = $server['CONTENT_TYPE']; // Add ContentType to headers
            if (isset($server['CONTENT_LENGTH'])) $headers['content-length'] = $server['CONTENT_LENGTH']; // Add ContentLength to headers
            $contentType = explode(';', $server['CONTENT_TYPE'], 2)[0]; // Ignore ContentType parameters for switch case

            if ($contentOverride === null)
                switch ($contentType)
                {
                    case 'application/json':
                        $content = json_decode(file_get_contents('php://input'), true);
                        break;

                        
                    case 'application/xml':
                        $parser = xml_parser_create();
                        $content = xml_parse($parser, file_get_contents('php://input'), true);
                        xml_parser_free($parser);
                        break;

                    case 'multipart/form-data':
                        if ($this->Method !== 'post') 
                            error_log('Invalid Request-Method for MIME-Type: ' . $contentType);
                        else 
                            $content = $_POST;
                        break;

                    case 'application/x-www-form-urlencoded':
                        foreach (explode('&', file_get_contents('php://input')) as $queryElem)
                        {
                            $components = explode('=', $queryElem, 2);
                            $content[$components[0]] = urldecode($components[1]);
                        }
                        break;

                    default: // If nothing worked get content raw
                        $content = file_get_contents('php://input');
                        break;
                }
        }

        if (isset($headers['x-forwarded-for'])) // Check for Proxy Server
        {
            $this->RemoteEndPoint = "{$headers['x-forwarded-for']}:-1";
            $this->ProxyEndPoint = "{$server['REMOTE_ADDR']}:{$server['REMOTE_PORT']}";
        }
        else
        {
            $this->RemoteEndPoint = "{$server['REMOTE_ADDR']}:{$server['REMOTE_PORT']}";
            $this->ProxyEndPoint = null;
        }

        $this->LocalEndPoint = "{$server['SERVER_ADDR']}:{$server['SERVER_PORT']}";

        $this->ContentType = $contentType;
        $this->Content = $content;
        $this->Headers = $headers;
    }

    /**
     * @return bool
     * Tests if the current method is the provided method string
     */
    public function isMethod(string $method)
    {
        return strtolower($this->Method) === $method;
    }

    /**
     * @return string|bool
     * Get's the value of a HTTP-Header, will be false if the header doesn't exist
     */
    public function getHeader(string $name)
    {
        $name = str_replace('_', '-', strtolower($name));
        if (!array_key_exists($name, $this->Headers)) return false;
        return $this->Headers[$name];
    }

    /**
     * @return bool
     * Checks if the header exists
     */
    public function containsHeader(string $name)
    {
        if ($this->getHeader($name) === false) return false;
        return true;
    }

    /**
     * @return string|false
     * Get the element from the query component, will be false if the element doesn't exist
     */
    public function getQuery(string $name)
    {
        if (!array_key_exists($name, $this->Query)) return false;
        return $this->Query[$name];
    }


    /**
     * @return string|null
     * @deprecated Use $ContentType
     */
    public function getContentType()
    {
        return $this->ContentType;
    }
}


// Update Check
if (true) // Set false to disable, true to enable
{
    $onlineVersion = file_get_contents('https://raw.githubusercontent.com/Nomris/php-misc-libs/main/web_data.php');
    if ($onlineVersion === false)
        error_log('WARN: ' . __FILE__ . '> Unbale to check for update');
    else
    {
        $onlineVersion = hash('sha256', $onlineVersion);
        $localversion = hash('sha256', file_get_contents(__FILE__));
        if ($localversion !== $onlineVersion)
            error_log('WARN: ' . __FILE__ . '> Online Version and Local Version differ');
    }
}