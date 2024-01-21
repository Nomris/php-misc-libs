<?php
/**
 * @return string|null
 */
function get_header(string $name)
{
    $name = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER[$name])) return $_SERVER[$name];
    return null;
}

final class RequestData
{
    public readonly string $Method;

    public readonly string $RawUri;

    public readonly string $Schema;

    public readonly string $Host;

    /**
     * @var array<string>
     */
    public $Path;

    public readonly array $Query;

    public readonly array $Headers;

    public readonly string $RemoteEndPoint;

    public readonly string $LocalEndPoint;

    public readonly string $ProxyEndPoint;

    public readonly string|null $ContentType;

    public readonly mixed $Content;

    public function __construct()
    {
        $this->Method = strtolower($_SERVER['REQUEST_METHOD']);
        $this->Schema = strtolower($_SERVER['REQUEST_SCHEME']);
        $url = urldecode($_SERVER['REQUEST_URI']);
        
        $this->Host = $_SERVER['HTTP_HOST'];
        $this->RawUri = $this->Schema . '://' . $this->Host . $url;

        if ($url[0] == '/') $url = substr($url, 1);

        $segments = explode('?', $url, 2);
        if (count($segments) == 1) $segments[1] = '';
        $path = explode('/', $segments[0]);
        $lastElem = count($path) == 0 ? null : max(0, count($path) - 1);
        if ($path)
        {
            if (trim($path[$lastElem]) === '') unset($path[$lastElem]);
        }
        $this->Path = $path;

        $rawQuery = trim($segments[1]) === '' ? array() : explode('&', $segments[1]);
        $query = array();
        
        $i = count($rawQuery) - 1;
        while ($i >= 0)
        {
            $querySegements = explode('=', $rawQuery[$i], 2);
            if (count($querySegements) == 1) $querySegements[1] = true;
            $query[$querySegements[0]] = $querySegements[1];
            $i--;
        }

        $this->Query = $query;

        $headers = array();
        $_serverKeys = array_keys($_SERVER);
        $i = count ($_serverKeys) - 1;
        while ($i >= 0)
        {
            if (str_starts_with($_serverKeys[$i], 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($_serverKeys[$i], 5)))] = $_SERVER[$_serverKeys[$i]];
            $i--;
        }

        $contentType = null;
        $content = null;
        if (isset($_SERVER['CONTENT_TYPE'])) 
        {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
            if (isset($_SERVER['CONTENT_LENGTH'])) $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
            $contentType = explode(';', $_SERVER['CONTENT_TYPE'], 2)[0];
            switch ($contentType)
            {
                case 'application/json':
                    $content = json_decode(file_get_contents('php://input'), true);
                    break;

                case 'multipart/form-data':
                    if (!$this->isMethod('post')) error_log('Invalid Request-Method for MIME-Type: ' . $contentType);
                    else $content = $_POST;
                    break;

                case 'application/x-www-form-urlencoded':
                    foreach (explode('&', file_get_contents('php://input')) as $queryElem)
                    {
                        $components = explode('=', $queryElem, 2);
                        $content[$components[0]] = urldecode($components[1]);
                    }
                    break;

                default:
                    $content = file_get_contents('php://input');
                    break;
            }
        }

        if (isset($headers['x-forwarded-for']))
        {
            $this->RemoteEndPoint = "{$headers['x-forwarded-for']}:0";
            $this->ProxyEndPoint = "{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']}";
        }
        else
        {
            $this->RemoteEndPoint = "{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']}";
            $this->ProxyEndPoint = null;
        }

        $this->LocalEndPoint = "{$_SERVER['SERVER_ADDR']}:{$_SERVER['SERVER_PORT']}";

        $this->ContentType = $contentType;
        $this->Content = $content;
        $this->Headers = $headers;
    }

    /**
     * @return bool
     */
    public function isMethod(string $method)
    {
        return strtolower($this->Method) === $method;
    }

    /**
     * @return string|bool
     */
    public function getHeader(string $name)
    {
        $name = str_replace('_', '-', strtolower($name));
        if (!array_key_exists($name, $this->Headers)) return false;
        return $this->Headers[$name];
    }

    /**
     * @return bool
     */
    public function containsHeader(string $name)
    {
        if ($this->getHeader($name) === false) return false;
        return true;
    }

    /**
     * @return string|false
     */
    public function getQuery(string $name)
    {
        if (!array_key_exists($name, $this->Query)) return false;
        return $this->Query[$name];
    }


    /**
     * @return string|null
     */
    public function getContentType()
    {
        if (!$this->containsHeader('content-type')) return null;
        return explode(';', $this->Headers['content-type'], 2)[0];
    }
}

?>