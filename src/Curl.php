<?php

namespace S25\Curl {

  class Curl
  {
    /**
     * Значения для CURLOPT_AUTOREFERER, при котором CURLOPT_REFERER будет заполнятся автоматически
     * не только при редиректе (CURLOPT_FOLLOWLOCATION), но и при следующем вызове req(),
     * значение будет получено из CURLINFO_EFFECTIVE_URL
     */
    const AUTOREFERER_ALL   = 'all';
    const AUTOREFERER_HOST  = 'host'; // Подстановка CURLOPT_REFERER только при запросе на тот же хост

    const JSONFIELDS = 'json_fields'; // Конвертирует значение в JSON и помещает в CURLOPT_POSTFIELDS

    protected $curl = null;
    protected $commonOpts = [];

    protected $lastUrl = null;

    /**
     * Curl constructor.
     * @param array $commonOpts
     *
     * @throws Exception
     */
    public function __construct(array $commonOpts = [])
    {
      $this->commonOpts = self::transpileOpts(self::parseHeadersInOpts($commonOpts));
    }

    public function __destruct()
    {
      $this->close();
    }

    /**
     * Выполняет запрос и возвращает результат
     * @param string $url
     * @param array $opts
     * @return Response
     *
     * @throws Exception
     */
    public function req(string $url, array $opts = []): Response
    {
      if ($this->curl === null)
      {
        $this->curl = curl_init();
      }
      else
      {
        $this->lastUrl = $this->getLastUrl();
        curl_reset($this->curl);
      }

      $resultOpts = $this->reqOpts($url, $opts);

      curl_setopt_array($this->curl, $resultOpts);

      $response = curl_exec($this->curl);

      if ($resultOpts[CURLOPT_VERBOSE] > 1)
      {
        echo $response."\n";
      }

      if ($response === false)
      {
        $response = Response::fromError($url, curl_error($this->curl));
      }
      else if (isset($resultOpts[CURLOPT_RETURNTRANSFER]) === false || !$resultOpts[CURLOPT_RETURNTRANSFER])
      {
        $response = Response::fromUrl($url);
      }
      else if ($resultOpts[CURLOPT_HEADER] ?? null)
      {
        $response = Response::fromResponse($url, $response);
      }
      else
      {
        $response = Response::fromBody($url, $response);
      }

      return $response;
    }

    /**
     * Вычисляет результирующие параметры curl, объединяя текущие ($opts) и общие ($common)
     * @param string|null $url
     * @param array $opts
     * @return array
     *
     * @throws Exception
     */
    public function reqOpts(string $url = null, array $opts = []): array
    {
      $opts = self::transpileOpts(self::parseHeadersInOpts($opts));

      // Отдельно вычислем заголовки
      $headers = self::stringifyHeaders($opts[CURLOPT_HTTPHEADER] + $this->commonOpts[CURLOPT_HTTPHEADER]);
      $headersOpt = count($headers) > 0 ? [CURLOPT_HTTPHEADER => $headers] : [];

      // Приоритет применения опций от большего к меньшему:
      // предвычесленные заголовки, текущие опции, наименьший приоритет у общих опций.
      $resultOpts = ($url ? [CURLOPT_URL => $url] : []) + $headersOpt + $opts + $this->commonOpts;
      // Вывод отладочных данных
      $resultOpts[CURLOPT_VERBOSE] = isset($resultOpts[CURLOPT_VERBOSE]) && $resultOpts[CURLOPT_VERBOSE] > 0;

      $resultOpts = $this->detectReferrer($resultOpts);

      // По-умолчанию считаем, что:
      // CURLOPT_RETURNTRANSFER === true, т.к. иначе ответ будет выведен в стандартный поток вывода
      $resultOpts[CURLOPT_RETURNTRANSFER] = $resultOpts[CURLOPT_RETURNTRANSFER] ?? true;
      // CURLOPT_HEADER === true, т.к. иначе ответ не будет содержать статуса
      $resultOpts[CURLOPT_HEADER] = $resultOpts[CURLOPT_HEADER] ?? true;
      // нельзя передавать файлы через поля с префиксом '@', т.к. небезопасно. Используем CURLFile вместо этого
      $resultOpts[CURLOPT_SAFE_UPLOAD] = $resultOpts[CURLOPT_SAFE_UPLOAD] ?? true;

      return $resultOpts;
    }

    public function close(): self
    {
      if ($this->curl !== null)
      {
        curl_close($this->curl);
        $this->curl = null;
        $this->lastUrl = null;
      }
      return $this;
    }

    // Информация о последнем запросе

    public function getLastUrl()
    {
      if ($this->curl)
      {
        return curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL) ?: $this->lastUrl;
      }
      return null;
    }

    // Работа с кукисами

    /**
     * @param string $host
     * @param bool $withExpired
     * @return Cookie[]
     *
     * @throws Exception
     */
    public function getCookies(string $host = '', bool $withExpired = false): array
    {
      $this->initBeforeReq();
      $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
      $cookies = array_filter(array_map([Cookie::class, 'parseNetscapeCookie'], $cookies));

      if ($host)
      {
        $cookies = array_filter($cookies, function (Cookie $cookie) use ($host) {
          return $cookie->matchHost($host);
        });
      }

      if ($withExpired === false)
      {
        $cookies = array_filter($cookies, function (Cookie $cookie) {
          return $cookie->isExpired() === false;
        });
      }

      return $cookies;
    }

    /**
     * @param array $cookies
     * @return curl
     *
     * @throws Exception
     */
    public function addCookies(array $cookies): self
    {
      $this->initBeforeReq();

      foreach ($cookies as $cookie)
      {
        if (is_array($cookie))
        {
          $cookie = Cookie::fromArray($cookie);
        }

        if ($cookie instanceof Cookie === false)
        {
          continue;
        }

        if (curl_setopt($this->curl, CURLOPT_COOKIELIST, $cookie->stringifyNetscape()) === false)
        {
          throw new Exception(curl_error($this->curl));
        }
      }
      return $this;
    }

    /**
     * Просрочивает куки, т.о. они не будут отправлены при следующем запросе
     * @param Cookie[] $cookies
     * @return curl
     *
     * @throws Exception
     */
    public function expireCookies(array $cookies): self
    {
      $removeCookies = [];
      foreach ($cookies as $cookie)
      {
        if (is_array($cookie))
        {
          $cookie = Cookie::fromArray($cookie);
        }

        if ($cookie instanceof Cookie === false)
        {
          continue;
        }

        $removeCookie = clone $cookie;
        $removeCookie->expire = 1;
        $removeCookies[] = $removeCookie;
      }

      $this->addCookies($removeCookies);
      return $this;
    }

    /**
     * @return Curl
     *
     * @throws Exception
     */
    public function clearCookies(): self
    {
      $this->initBeforeReq();
      if (curl_setopt($this->curl, CURLOPT_COOKIELIST, 'ALL') === false)
      {
        throw new Exception(curl_error($this->curl));
      }
      return $this;
    }

    // Вспомогательные методы

    /**
     * @param $url
     * @param null $params
     * @return Response
     *
     * @throws Exception
     */
    public function get($url, $params = null): Response
    {
      $url = $params ? self::appendQueryToUrl($url, $params) : $url;
      return $this->req($url);
    }

    /**
     * Выполянет POST запрос, передевая параметры в кодировке application/x-www-form-urlencoded
     * @param $url
     * @param null $params
     * @return Response
     *
     * @throws Exception
     */
    public function post($url, $params = null): Response
    {
      return $this->req($url, array_filter([
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params ? http_build_query($params) : null,
      ]));
    }

    /**
     * Выполянет POST запрос, передевая параметры в кодировке multipart/form-data, если параметры получены в массиве
     * @param $url
     * @param null $params
     * @return Response
     *
     * @throws Exception
     */
    public function postMulti($url, $params = null): Response
    {
      return $this->req($url, array_filter([
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
      ]));
    }

    /**
     * Выполянет POST запрос, передевая параметры в JSON
     * @param $url
     * @param null $params
     * @return Response
     *
     * @throws Exception
     */
    public function postJson($url, $params = null): Response
    {
      return $this->req($url, array_filter([
        CURLOPT_POST => true,
        self::JSONFIELDS => $params,
      ]));
    }

    // Protected

    /**
     * @throws Exception
     */
    protected function initBeforeReq()
    {
      if ($this->curl === null)
      {
        $this->curl = curl_init();

        curl_setopt_array($this->curl, $this->reqOpts());
      }
    }

    protected function detectReferrer(array $opts): array
    {
      if (isset($opts[CURLOPT_AUTOREFERER]) === false)
      {
        return $opts;
      }

      $referrer = $opts[CURLOPT_REFERER] ?? null;

      if (!$referrer)
      {
        if ($opts[CURLOPT_AUTOREFERER] === self::AUTOREFERER_ALL)
        {
          $referrer = $this->getLastUrl();
        }
        else if ($opts[CURLOPT_AUTOREFERER] === self::AUTOREFERER_HOST && isset($opts[CURLOPT_URL]))
        {
          $lastUrl = $this->getLastUrl();
          $lastUrlHost  = mb_strtolower(parse_url($lastUrl, PHP_URL_HOST), 'utf8');
          $curUrlHost   = mb_strtolower(parse_url($opts[CURLOPT_URL], PHP_URL_HOST), 'utf8');

          if ($lastUrlHost === $curUrlHost)
          {
            $referrer = $lastUrl;
          }
        }
      }

      if ($referrer)
      {
        $opts[CURLOPT_REFERER] = $referrer;
      }
      else
      {
        unset($opts[CURLOPT_REFERER]);
      }

      $opts[CURLOPT_AUTOREFERER] = boolval($opts[CURLOPT_AUTOREFERER]);

      return $opts;
    }

    /**
     * Преобразует список строк с HTTP-заголовками в ассоциативный массив ['Нормализованное-Имя-Заголовка' => 'значение']
     * @param array $headers
     * @return array
     *
     * @throws Exception
     */
    protected static function parseHeaders(array $headers): array
    {
      $parsed = [];
      foreach ($headers as $key => $header)
      {
        if (is_numeric($key))
        {
          $headerParts = explode(':', $header, 2);
          if (count($headerParts) < 2)
          {
            throw new Exception('Отсутствует разделитель HTTP-заголовка и его значения');
          }
          $key = trim($headerParts[0]);
          $header = trim($headerParts[1]);
        }
        $key = implode('-', array_map('ucfirst', explode('-', strtolower($key))));
        $parsed[$key] = $header;
      }
      return $parsed;
    }

    /**
     * Преобразует ассоциативный массив с HTTP-заголовком в список строк
     * @see ParserCurl::parseHeaders
     *
     * @param array $headers
     * @return array
     */
    protected static function stringifyHeaders(array $headers): array
    {
      $stringified = [];
      foreach ($headers as $name => $value)
      {
        $stringified[] = "$name: $value";
      }
      return $stringified;
    }

    /**
     * Преобразует список строк с HTTP-заголовками из набора опций для cUrl (если там такой имеется) в ассоциативный массив
     * Преобразование выполняется удобства смерживания наборов опций
     * @param array $opts
     * @return array
     *
     * @throws Exception
     */
    protected static function parseHeadersInOpts(array $opts): array
    {
      $opts[CURLOPT_HTTPHEADER] = self::parseHeaders($opts[CURLOPT_HTTPHEADER] ?? []);
      return $opts;
    }

    /**
     * Преобразует некоторые расширенные опции s25Curl в нативные cUrl-овые опции
     *
     * @param array $opts
     * @return array
     */
    protected static function transpileOpts(array $opts): array
    {
      if (array_key_exists(self::JSONFIELDS, $opts) === false)
      {
        return $opts;
      }
      $opts[CURLOPT_POSTFIELDS] = json_encode($opts[self::JSONFIELDS]);
      unset($opts[self::JSONFIELDS]);
      return $opts;
    }



    protected static function appendQueryToUrl(string $url, $params): string
    {
      $params = is_string($params) ? $params : http_build_query($params);

      return preg_replace_callback(
        '/(?<query>\?[^#]*)?(?<hash>#.*)?$/u',
        function($match) use ($params) {
          $query = $match['query'] ?? '';
          $hash = $match['hash'] ?? '';
          return $query . ($query ? '&' : '?') . $params . $hash;
        },
        $url
      );
    }
  }
}