<?php
//邮件操作类，目前只支持smtp服务的邮件发送
//2020-12-18 https://github.com/PHPMailer/PHPMailer

defined ( 'SYSTEM_FLAG' ) or exit( 'Access Invalid!' );

final class Email
{
    /**
     * The PHPMailer SMTP version number.
     * @var string
     */
    const VERSION = '6.6.4';

    /**
     * SMTP line break constant.
     *
     * @var string
     */
    const LE = "\r\n";

    /**
     * The SMTP port to use if one is not specified.
     *
     * @var int
     */
    const DEFAULT_PORT = 25;

    /**
     * The maximum line length allowed by RFC 5321 section 4.5.3.1.6,
     * *excluding* a trailing CRLF break.
     *
     * @see https://tools.ietf.org/html/rfc5321#section-4.5.3.1.6
     *
     * @var int
     */
    const MAX_LINE_LENGTH = 998;

    /**
     * The maximum line length allowed for replies in RFC 5321 section 4.5.3.1.5,
     * *including* a trailing CRLF line break.
     *
     * @see https://tools.ietf.org/html/rfc5321#section-4.5.3.1.5
     *
     * @var int
     */
    const MAX_REPLY_LENGTH = 512;

    /**
     * Debug level for no output.
     *
     * @var int
     */
    const DEBUG_OFF = 0;

    /**
     * Debug level to show client -> server messages.
     *
     * @var int
     */
    const DEBUG_CLIENT = 1;

    /**
     * Debug level to show client -> server and server -> client messages.
     *
     * @var int
     */
    const DEBUG_SERVER = 2;

    /**
     * Debug level to show connection status, client -> server and server -> client messages.
     *
     * @var int
     */
    const DEBUG_CONNECTION = 3;

    /**
     * Debug level to show all messages.
     *
     * @var int
     */
    const DEBUG_LOWLEVEL = 4;
    /**
     * The socket for the server connection.
     *
     * @var ?resource
     */
    protected $smtp_conn;

    /**
     * Error information, if any, for the last SMTP command.
     *
     * @var array
     */
    public $error = [
        'error' => '',
        'detail' => '',
        'smtp_code' => '',
        'smtp_code_ex' => '',
    ];
    /**
     * Options array passed to stream_context_create when connecting via SMTP.
     *
     * @var array
     */
    public $SMTPOptions = [];

    private $debug = TRUE;
    /**
     * 邮件服务器
     */
    private $email_server;
    /**
     * 端口
     */
    private $email_port = 25;
    /**
     * 账号
     */
    private $email_user;
    /**
     * 密码
     */
    private $email_password;
    /**
     * 发送邮箱
     */
    private $email_from;
    /**
     * 间隔符
     */
    private $email_delimiter = "\n";
    /**
     * 站点名称
     */
    private $site_name;

    public function get($key)
    {
        if (!empty($this->$key)) {
            return $this->$key;
        } else {
            return FALSE;
        }
    }

    public function set($key, $value)
    {
        //20220915 debug
        $this->$key = $value;
        return TRUE;

        #if ( ! isset( $this->$key ) ) {
        #	$this->$key = $value;
        #
        #	return TRUE;
        #} else {
        #	return FALSE;
        #}
    }

    // 20220915 阿里云服务器环境优化

    /**
     * Create connection to the SMTP server.
     * @param string $host SMTP server IP or host name
     * @param int $port The port number to connect to
     * @param int $timeout How long to wait for the connection to open
     * @param array $options An array of options for stream_context_create()
     * @return false|resource
     */
    protected function getSMTPConnection($host, $port = 465, $timeout = 30, $options = [])
    {
        static $streamok;
        //This is enabled by default since 5.0.0 but some providers disable it
        //Check this once and cache the result
        if (null === $streamok) {
            $streamok = function_exists('stream_socket_client');
        }

        #d($host, false);
        #$host = 'ssl://'.$host.':'.$port;
        ##$host1 = 'ssl://smtp.exmail.qq.com:465';
        #$socket_context = stream_context_create($options);
        #set_error_handler([$this, 'errorHandler']);
        #$connection = stream_socket_client(
        #    $host,
        #    $errno,
        #    $errstr,
        #    10,
        #    STREAM_CLIENT_CONNECT,
        #    $socket_context
        #);
        #$connection = pfsockopen(
        #    $host,
        #    $port,
        #    $errno,
        #    $errstr,
        #    $timeout
        #);
        #d($host,false); d($connection);

        $errno = 0;
        $errstr = '';
        set_error_handler([$this, 'errorHandler']);
        if ($streamok == true) {
            $socket_context = stream_context_create($options);
            #set_error_handler([$this, 'errorHandler']);
            $connection = stream_socket_client(
                $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $socket_context
            );
        } else {
            //Fall back to fsockopen which should work in more places, but is missing some features
            $this->edebug(
                'Connection: stream_socket_client not available, falling back to fsockopen',
                self::DEBUG_CONNECTION
            );
            #set_error_handler([$this, 'errorHandler']);
            $connection = fsockopen(
                $host,
                $port,
                $errno,
                $errstr,
                $timeout
            );
        }
        restore_error_handler();

        //Verify we connected properly
        if (!is_resource($connection)) {
            $this->setError(
                'Failed to connect to server',
                '',
                (string)$errno,
                $errstr
            );
            $this->edebug(
                'SMTP ERROR: ' . $this->error['error']
                . ": $errstr ($errno)",
                self::DEBUG_CONNECTION
            );

            return false;
        }

        //SMTP server can take longer to respond, give longer timeout for first read
        //Windows does not have support for this timeout function
        if (strpos(PHP_OS, 'WIN') !== 0) {
            $max = (int)ini_get('max_execution_time');
            //Don't bother if unlimited, or if set_time_limit is disabled
            if (0 !== $max && $timeout > $max && strpos(ini_get('disable_functions'), 'set_time_limit') === false) {
                @set_time_limit($timeout);
            }
            stream_set_timeout($connection, $timeout, 0);
        }
        return $connection;
    }

    /**
     * 发送邮件
     *
     * @param string $email_to 发送对象邮箱地址
     * @param string $subject 邮件标题
     * @param string $message 邮件内容
     * @param string $from 页头来源内容
     *
     * @return bool 布尔形式的返回结果
     */
    public function send($email_to, $subject, $message, $from = '')
    {
        if (empty($email_to)) return FALSE;
        $message = base64_encode($this->html($subject, $message));
        $email_to = $this->to($email_to);
        $header = $this->header($from, $message);

        //发送 旧代码20220913
        #$fp = @fsockopen ( $this->email_server , $this->email_port , $errno , $errstr , 30 ); var_dump($fp); d($fp);

        //debug 20220915 阿里云服务器环境优化 增加getSMTPConnection($host, $port = null, $timeout = 30, $options = [])
        $_smtp_options = !empty($this->SMTPOptions) ? $this->SMTPOptions : [
            'ssl' => [
                'verify_peer' => false, //是否需要验证 SSL 证书
                'verify_peer_name' => false, //是否需要验证 peer name
                'allow_self_signed' => true  //是否允许自签名证书。需要配合 verify_peer 参数使用（注：当 verify_peer 参数为 true 时才会根据 allow_self_signed 参数值来决定是否允许自签名证书）。
            ]
        ];
        $fp = $this->getSMTPConnection($this->email_server, $this->email_port, 20, $_smtp_options); //var_dump($fp); d($fp,false);
        $this->resultLog(" CONNECT server: " . $this->email_server . ':' . $this->email_port);
        $this->resultLog($fp);
        if (!is_resource($fp)) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " CONNECT - Unable to connect to the SMTP server");
            return FALSE;
        }
        stream_set_blocking($fp, TRUE);   // 设置阻塞
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != '220') {
            $this->resultLog($this->email_server . ':' . $this->email_port . $lastmessage);
            return FALSE;
        }
        fputs($fp, 'EHLO' . " test\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 220 && substr($lastmessage, 0, 3) != 250) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " HELO/EHLO - $lastmessage");
            return FALSE;
        } elseif (substr($lastmessage, 0, 3) == 220) {
            $lastmessage = fgets($fp, 512);
            if (substr($lastmessage, 0, 3) != 250) {
                $this->resultLog($this->email_server . ':' . $this->email_port . " HELO/EHLO - $lastmessage");
                return FALSE;
            }
        }
        while (1) {
            if (substr($lastmessage, 3, 1) != '-' || empty($lastmessage)) {
                break;
            }
            $lastmessage = fgets($fp, 512);
        }
        fputs($fp, "AUTH LOGIN\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 334) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " AUTH LOGIN - $lastmessage");
            return FALSE;
        }
        fputs($fp, base64_encode($this->email_user) . "\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 334) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " USERNAME - $lastmessage");
            return FALSE;
        }
        fputs($fp, base64_encode($this->email_password) . "\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 235) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " PASSWORD - $lastmessage");
            return FALSE;
        }
        fputs($fp, "MAIL FROM: <" . preg_replace("/.*\<(.+?)\>.*/", "\\1", $this->email_from) . ">\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 250) {
            fputs($fp, "MAIL FROM: <" . preg_replace("/.*\<(.+?)\>.*/", "\\1", $this->email_from) . ">\r\n");
            $lastmessage = fgets($fp, 512);
            if (substr($lastmessage, 0, 3) != 250) {
                $this->resultLog($this->email_server . ':' . $this->email_port . " MAIL FROM - $lastmessage");
                return FALSE;
            }
        }
        fputs($fp, "RCPT TO: <" . preg_replace("/.*\<(.+?)\>.*/", "\\1", $email_to) . ">\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 250) {
            fputs($fp, "RCPT TO: <" . preg_replace("/.*\<(.+?)\>.*/", "\\1", $email_to) . ">\r\n");
            $lastmessage = fgets($fp, 512);
            $this->resultLog($this->email_server . ':' . $this->email_port . " RCPT TO - $lastmessage");
            return FALSE;
        }
        fputs($fp, "DATA\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 354) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " DATA - $lastmessage");
            return FALSE;
        }
        fputs($fp, "Date: " . gmdate('r') . "\r\n");
        fputs($fp, "To: " . $email_to . "\r\n");
        fputs($fp, "Subject: " . $subject . "\r\n");
        fputs($fp, $header . "\r\n");
        fputs($fp, "\r\n\r\n");
        fputs($fp, "$message\r\n.\r\n");
        $lastmessage = fgets($fp, 512);
        if (substr($lastmessage, 0, 3) != 250) {
            $this->resultLog($this->email_server . ':' . $this->email_port . " END - $lastmessage");
        }
        fputs($fp, "QUIT\r\n");
        return TRUE;
    }

    public function send_sys_email($email_to, $subject, $message)
    {
        $this->set_sys_config(); //d($this);
        $result = $this->send($email_to, $subject, $message);
        return $result;
    }

    //20220915 设置系统邮箱配置
    public function set_sys_config()
    {
        $this->set('email_server', C('email_host'));
        $this->set('email_port', C('email_port'));
        $this->set('email_user', C('email_id'));
        $this->set('email_password', C('email_pass'));
        $this->set('email_from', C('email_addr'));
        $this->set('site_name', C('site_name')); //d($this);
        return $this;
    }

    /**
     * Reports an error number and string.
     *
     * @param int $errno The error number returned by PHP
     * @param string $errmsg The error message returned by PHP
     * @param string $errfile The file the error occurred in
     * @param int $errline The line number the error occurred on
     */
    protected function errorHandler($errno, $errmsg, $errfile = '', $errline = 0)
    {
        $notice = 'Connection failed.';
        $this->setError(
            $notice,
            $errmsg,
            (string)$errno
        );
        $this->edebug(
            "$notice Error #$errno: $errmsg [$errfile line $errline]",
            self::DEBUG_CONNECTION
        );
    }

    /**
     * Set error messages and codes.
     *
     * @param string $message The error message
     * @param string $detail Further detail on the error
     * @param string $smtp_code An associated SMTP error code
     * @param string $smtp_code_ex Extended SMTP code
     */
    protected function setError($message, $detail = '', $smtp_code = '', $smtp_code_ex = '')
    {
        $this->error = [
            'error' => $message,
            'detail' => $detail,
            'smtp_code' => $smtp_code,
            'smtp_code_ex' => $smtp_code_ex,
        ];
    }

    /**
     * Output debugging info via a user-selected method.
     *
     * @param string $str Debug string to output
     * @param int $level The debug level of this message; see DEBUG_* constants
     *
     * @see SMTP::$Debugoutput
     * @see SMTP::$do_debug
     */
    protected function edebug($str, $level = 3)
    {
        switch ($level) {
            case 4:
            case 3:
            case 2:
            case 1:
                //Don't output, just log
                //Cleans up output a bit for a better looking, HTML-safe output
                $str = gmdate('Y-m-d H:i:s') . "\t" . htmlentities(
                        preg_replace('/[\r\n]+/', '', $str),
                        ENT_QUOTES,
                        'UTF-8'
                    ) . "<br>\r\n";
                $this->resultLog($str);
                break;
            case 0:
            default:
                //Normalize line breaks
                $str = preg_replace('/\r\n|\r/m', "\n", $str);
                echo gmdate('Y-m-d H:i:s'),
                "\t",
                    //Trim trailing space
                trim(
                //Indent for readability, except for trailing break
                    str_replace(
                        "\n",
                        "\n                   \t                  ",
                        trim($str)
                    )
                ),
                "\n";
        }
    }

    /**
     * 内容:邮件主体
     *
     * @param string $subject 邮件标题
     * @param string $message 邮件内容
     *
     * @return string 字符串形式的返回结果
     */
    private function html($subject, $message)
    {
        $message = preg_replace("/href\=\"(?!http\:\/\/)(.+?)\"/i", 'href="' . SHOP_SITE_URL . '\\1"', $message);
        $tmp = "<html><head>";
        $tmp .= '<meta http-equiv="Content-Type" content="text/html; charset=' . CHARSET . '">';
        $tmp .= "<title>" . $subject . "</title>";
        $tmp .= "</head><body>" . $message . "</body></html>";
        $message = $tmp;
        unset($tmp);
        return $message;
    }

    /**
     * 发送对象邮件地址
     *
     * @param string $email_to 发送地址
     *
     * @return string 字符串形式的返回结果
     */
    private function to($email_to)
    {
        $email_to = preg_match('/^(.+?) \<(.+?)\>$/', $email_to, $mats) ? ($this->email_user ? '=?' . CHARSET . '?B?' . base64_encode($mats[1]) . "?= <$mats[2]>" : $mats[2]) : $email_to;

        return $email_to;
    }

    /**
     * 内容:邮件标题
     *
     * @param string $subject 邮件标题
     *
     * @return string 字符串形式的返回结果
     */
    private function subject($subject)
    {
        $subject = '=?' . CHARSET . '?B?' . base64_encode(preg_replace("/[\r|\n]/", '', '[' . $this->site_name . '] ' . $subject)) . '?=';

        return $subject;
    }

    /**
     * 内容:邮件主体内容
     *
     * @param string $message 邮件主体内容
     *
     * @return string 字符串形式的返回结果
     */
    private function message($message)
    {
        $message = chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r", "\n", str_replace("\r\n", "\n", str_replace("\n\r", "\r", $message))))));

        return $message;
    }

    /**
     * 内容:邮件页头
     *
     * @param string $from 邮件页头来源
     *
     * @return array $rs_row 返回数组形式的查询结果
     */
    private function header($from = '', $message = '')
    {
        if ($from == '') {
            $from = '=?' . CHARSET . '?B?' . base64_encode($this->site_name) . "?= <" . $this->email_from . ">";
        } else {
            $from = preg_match('/^(.+?) \<(.+?)\>$/', $from, $mats) ? '=?' . CHARSET . '?B?' . base64_encode($mats[1]) . "?= <$mats[2]>" : $from;
        }
        $header = "From: $from{$this->email_delimiter}";
        $header .= "X-Priority: 3{$this->email_delimiter}";
        $header .= "X-Mailer: test {$this->email_delimiter}";
        $header .= "MIME-Version: 1.0{$this->email_delimiter}";
        $header .= "Content-type: text/html; ";
        $header .= "charset=" . CHARSET . "{$this->email_delimiter}";
        $header .= "Content-Transfer-Encoding: base64{$this->email_delimiter}";
        $header .= 'Message-ID: <' . gmdate('YmdHs') . '.' . substr(md5($message . microtime()), 0, 6) . rand(100000, 999999) . '@' . $_SERVER['HTTP_HOST'] . ">{$this->email_delimiter}";
        return $header;
    }

    /**
     * 错误信息记录
     *
     * @param string $msg 错误信息
     *
     * @return bool 布尔形式的返回结果
     */
    private function resultLog($msg){
        if ($this->debug === TRUE) {
            $_fnm = BASE_DATA_PATH . '/log/email_log.php';
            $_exists = file_exists($_fnm);
            $fp = fopen($_fnm, 'a+');
            if ($_exists == false) {
                fwrite($fp, "<?php exit; ?>\r\n");
            }
            $msg = is_string($msg) ? $msg : print_r($msg, true);
            fwrite($fp, $msg . "\r\n" );
            fclose($fp);
            return TRUE;
        } else {
            return TRUE;
        }
    }
}
