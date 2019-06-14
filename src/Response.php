<?php

namespace S25\Curl
{

    class Response
    {
        protected $url;       // URL-адрес запроса
        protected $version;   // Версия HTTP протокола
        protected $status;    // HTTP-код статуса
        protected $phrase;    // HTTP-фраза статуса
        protected $headers;   // Индексный мвссив HTTP-заголовков
        protected $body;      // Тело ответа
        protected $error;     // Ошибка подключения

        protected function __construct()
        {
        }

        #region Getters

        public function getVersion()
        {
            return $this->version;
        }

        public function getStatus()
        {
            return $this->status;
        }

        public function getPhrase()
        {
            return $this->phrase;
        }

        public function getHeaders()
        {
            return $this->headers;
        }

        public function getBody()
        {
            return $this->body;
        }

        public function getError()
        {
            return $this->error;
        }

        public function isError(): bool
        {
            return !!$this->error;
        }

        public function isOk(): bool
        {
            return $this->isError() === false && ($this->status === '200' || !$this->status);
        }

        #endregion Getters

        #region Helpers

        public function getHeadersAssoc()
        {
            if (is_array($this->headers) === false || empty($this->headers)) {
                return $this->headers;
            }

            $assocHeaders = [];
            foreach ($this->headers as $header)
            {
                list($key, $value) = explode(': ', $header, 2) + [null, null];

                $assocHeaders[$key] = $value;
            }

            return $assocHeaders;
        }

        /**
         * @param bool $assoc
         * @return mixed
         *
         * @throws Exception
         */
        public function getJson($assoc = true)
        {
            if (is_string($this->body) === false) {
                throw new Exception("Тело ответа не является JSON-строкой");
            }

            $json = json_decode($this->body, $assoc);

            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(json_last_error_msg());
            }

            return $json;
        }

        #endregion

        #region Constructors

        /**
         * Инстанцирование, если CURLOPT_RETURNTRANSFER === false
         * @param $url
         * @return Response
         */
        public static function fromUrl($url): self
        {
            $response = new self();
            $response->url = $url;
            return $response;
        }

        /**
         * Инстанцирование CURLOPT_RETURNTRANSFER === true &&
         * @param $url
         * @param $response
         * @return Response
         */
        public static function fromResponse($url, $response): self
        {
            list($httpVersion, $status, $phrase, $headers, $body) = self::parseResponse($response);

            while (in_array($status, ['100', '301', '302', '303'], true) && $body) {
                list($httpVersion, $status, $phrase, $headers, $body) = self::parseResponse($body);
            }

            $response = new self();
            $response->url = $url;
            $response->version = $httpVersion;
            $response->status = $status;
            $response->phrase = $phrase;
            $response->headers = $headers;
            $response->body = $body;
            return $response;
        }

        public static function fromBody($url, $body): self
        {
            $response = new self();
            $response->url = $url;
            $response->body = $body;
            return $response;
        }

        public static function fromError($url, $error): self
        {
            $response = new self();
            $response->url = $url;
            $response->error = $error;
            return $response;
        }

        protected static function parseResponse($response)
        {
            list($headers, $body) = preg_split('/\r\n\r\n/', $response, 2);
            $headers = preg_split('/\r\n/', $headers);
            $statusLine = array_shift($headers);
            list($httpVersion, $status, $phrase) = preg_split('/\s+/', $statusLine, 3);
            return [$httpVersion, $status, $phrase, $headers, $body];
        }

        #enregion Constructors
    }
}