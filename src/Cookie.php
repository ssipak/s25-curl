<?php

namespace S25\Curl
{

    class Cookie
    {
        private $host = null;   // Хост, для которого устанавливается кука

        public $sub    = false;  // Включая поддомены
        public $path   = '/';    // Путь
        public $secure = false;  // Только для HTTPS и SSL
        public $expire = 0;      // UNIX-метка, когда кука просрочивается.
        // 0 - сессионная кука, должна умирать при закрытии браузера
        public $name  = null;   // Имя куки
        public $value = null;   // Значение куки

        const FIELDS_COUNT = 7;
        const BOOL_VALUES  = [
            'TRUE' => true,
            'FALSE' => false,
        ];

        public function getHost(): string
        {
            return $this->host;
        }

        public function setHost(string $host = null, bool $sub = null)
        {
            if (!$host) {
                $this->host = null;
                $this->sub = false;
            } else {
                $this->host = preg_replace('/^\./u', '', $host, 1, $count);
                $this->sub = $sub || $count > 0;
            }
        }

        public function matchHost($host): bool
        {
            $isEqual = $host === $this->host;
            if (!$this->sub || $isEqual) {
                return $isEqual;
            }
            $hostRe = preg_quote($this->host, '/');
            return preg_match("/\.{$hostRe}$/uis", $host) === 1;
        }

        public function isExpired(): bool
        {
            return $this->expire > 0 && $this->expire < time();
        }

        // Конвертация

        public static function fromArray(array $array): self
        {
            $cookie = new Cookie();

            // @formatter:off
            $cookie->setHost($array['host'] ?? $cookie->host, $array['sub'] ?? null);
            $cookie->path = $array['path'] ?? $cookie->path;
            $cookie->secure = $array['secure'] ?? $cookie->secure;
            $cookie->expire = $array['expire'] ?? $cookie->expire;
            $cookie->name = $array['name'] ?? $cookie->name;
            $cookie->value = $array['value'] ?? $cookie->value;
            // @formatter:on

            return $cookie;
        }

        /**
         * @param string $line
         * @return Cookie|null
         */
        public static function parseNetscapeCookie(string $line)
        {
            $line = explode("\t", $line, self::FIELDS_COUNT);
            if (count($line) !== self::FIELDS_COUNT) {
                return null;
            }

            $cookie = new self();
            $cookie->setHost(
                self::BOOL_VALUES[$line[0]] ?? $line[0] ?? $cookie->host,
                self::BOOL_VALUES[$line[1]] ?? $cookie->sub
            );
            $cookie->path = $line[2];
            $cookie->secure = self::BOOL_VALUES[$line[3]] ?? null;
            $cookie->expire = $line[4];
            $cookie->name = $line[5];
            $cookie->value = $line[6];

            return $cookie;
        }

        /**
         * @return string|null
         */
        public function stringifyNetscape()
        {
            if (boolval($this->name) === false) {
                return null;
            }

            return implode("\t", [
                $this->host ?: 'FALSE',
                $this->sub ? 'TRUE' : 'FALSE',
                $this->path,
                $this->secure ? 'TRUE' : 'FALSE',
                $this->expire,
                $this->name,
                $this->value,
            ]);
        }
    }
}