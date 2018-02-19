<?php

/*

    Copyright (c) 2009-2015 F3::Factory/Bong Cosca, All rights reserved.

    This file is part of the Fat-Free Framework (http://fatfreeframework.com).

    This is free software: you can redistribute it and/or modify it under the
    terms of the GNU General Public License as published by the Free Software
    Foundation, either version 3 of the License, or later.

    Fat-Free Framework is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
    General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with Fat-Free Framework.  If not, see <http://www.gnu.org/licenses/>.

*/

//! SMTP plug-in
class smtp extends Magic
{
    //@{ Locale-specific error/exception messages
    const
        E_Header = '%s: header is required',
        E_Blank = 'Message must not be blank',
        E_Attach = 'Attachment %s not found';
    //@}

    //! Message properties
    protected $headers;
    //! E-mail attachments
    protected $attachments;
    //! SMTP host
    protected $host;
    //! SMTP port
    protected $port;
    //! TLS/SSL
    protected $scheme;
    //! User ID
    protected $user;
    //! Password
    protected $pw;
    //! TCP/IP socket
    protected $socket;
    //! Server-client conversation
    protected $log;

    /**
     *   Fix header.
     *
     *   @return string
     *
     *   @param $key string
     **/
    protected function fixheader($key)
    {
        return str_replace(' ', '-',
            ucwords(preg_replace('/[_-]/', ' ', strtolower($key))));
    }

    /**
     *   Return TRUE if header exists.
     *
     *   @return bool
     *
     *   @param $key
     **/
    public function exists($key)
    {
        $key = $this->fixheader($key);

        return isset($this->headers[$key]);
    }

    /**
     *   Bind value to e-mail header.
     *
     *   @return string
     *
     *   @param $key string
     *   @param $val string
     **/
    public function set($key, $val)
    {
        $key = $this->fixheader($key);

        return $this->headers[$key] = $val;
    }

    /**
     *   Return value of e-mail header.
     *
     *   @return string|NULL
     *
     *   @param $key string
     **/
    public function &get($key)
    {
        $key = $this->fixheader($key);
        if (isset($this->headers[$key])) {
            $val = &$this->headers[$key];
        } else {
            $val = null;
        }

        return $val;
    }

    /**
     *   Remove header.
     *
     *   @return NULL
     *
     *   @param $key string
     **/
    public function clear($key)
    {
        $key = $this->fixheader($key);
        unset($this->headers[$key]);
    }

    /**
     *   Return client-server conversation history.
     *
     *   @return string
     **/
    public function log()
    {
        return str_replace("\n", PHP_EOL, $this->log);
    }

    /**
     *   Send SMTP command and record server response.
     *
     *   @return string
     *
     *   @param $cmd string
     *   @param $log bool
     **/
    protected function dialog($cmd = null, $log = true)
    {
        $socket = &$this->socket;
        if (!is_null($cmd)) {
            fputs($socket, $cmd."\r\n");
        }
        $reply = '';
        while (!feof($socket) && ($info = stream_get_meta_data($socket)) &&
            !$info['timed_out'] && $str = fgets($socket, 4096)) {
            $reply .= $str;
            if (preg_match('/(?:^|\n)\d{3} .+?\r\n/s', $reply)) {
                break;
            }
        }
        if ($log) {
            $this->log .= $cmd."\n";
            $this->log .= str_replace("\r", '', $reply);
        }

        return $reply;
    }

    /**
     *   Add e-mail attachment.
     *
     *   @return NULL
     *
     *   @param $file string
     *   @param $alias string
     *   @param $cid string
     **/
    public function attach($file, $alias = null, $cid = null)
    {
        if (!is_file($file)) {
            user_error(sprintf(self::E_Attach, $file), E_USER_ERROR);
        }
        if (is_string($alias)) {
            $file = array($alias => $file);
        }
        $this->attachments[] = array('filename' => $file,'cid' => $cid);
    }

    /**
     *   Transmit message.
     *
     *   @return bool
     *
     *   @param $message string
     *   @param $log bool
     **/
    public function send($message, $log = true)
    {
        if ($this->scheme == 'ssl' && !extension_loaded('openssl')) {
            return false;
        }
        // Message should not be blank
        if (!$message) {
            user_error(self::E_Blank, E_USER_ERROR);
        }
        $fw = Base::instance();
        // Retrieve headers
        $headers = $this->headers;
        // Connect to the server
        $socket = &$this->socket;
        $socket = @fsockopen($this->host, $this->port);
        if (!$socket) {
            return false;
        }
        stream_set_blocking($socket, true);
        // Get server's initial response
        $this->dialog(null, false);
        // Announce presence
        $reply = $this->dialog('EHLO '.$fw->get('HOST'), $log);
        if (strtolower($this->scheme) == 'tls') {
            $this->dialog('STARTTLS', $log);
            stream_socket_enable_crypto(
                $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $reply = $this->dialog('EHLO '.$fw->get('HOST'), $log);
        }
        if (preg_match('/8BITMIME/', $reply)) {
            $headers['Content-Transfer-Encoding'] = '8bit';
        } else {
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
            $message = quoted_printable_encode($message);
        }
        if ($this->user && $this->pw && preg_match('/AUTH/', $reply)) {
            // Authenticate
            $this->dialog('AUTH LOGIN', $log);
            $this->dialog(base64_encode($this->user), $log);
            $this->dialog(base64_encode($this->pw), $log);
        }
        // Required headers
        $reqd = array('From','To','Subject');
        foreach ($reqd as $id) {
            if (empty($headers[$id])) {
                user_error(sprintf(self::E_Header, $id), E_USER_ERROR);
            }
        }
        $eol = "\r\n";
        $str = '';
        // Stringify headers
        foreach ($headers as $key => &$val) {
            if (!in_array($key, $reqd) && (!$this->attachments ||
                $key != 'Content-Type' && $key != 'Content-Transfer-Encoding')) {
                $str .= $key.': '.$val.$eol;
            }
            if (in_array($key, array('From', 'To', 'Cc', 'Bcc')) &&
                !preg_match('/[<>]/', $val)) {
                $val = '<'.$val.'>';
            }
            unset($val);
        }
        // Start message dialog
        $this->dialog('MAIL FROM: '.strstr($headers['From'], '<'), $log);
        foreach ($fw->split($headers['To'].
            (isset($headers['Cc']) ? (';'.$headers['Cc']) : '').
            (isset($headers['Bcc']) ? (';'.$headers['Bcc']) : '')) as $dst) {
            $this->dialog('RCPT TO: '.strstr($dst, '<'), $log);
        }
        $this->dialog('DATA', $log);
        if ($this->attachments) {
            // Replace Content-Type
            $type = $headers['Content-Type'];
            unset($headers['Content-Type']);
            $enc = $headers['Content-Transfer-Encoding'];
            unset($headers['Content-Transfer-Encoding']);
            $hash = uniqid(null, true);
            // Send mail headers
            $out = 'Content-Type: multipart/mixed; boundary="'.$hash.'"'.$eol;
            foreach ($headers as $key => $val) {
                if ($key != 'Bcc') {
                    $out .= $key.': '.$val.$eol;
                }
            }
            $out .= $eol;
            $out .= 'This is a multi-part message in MIME format'.$eol;
            $out .= $eol;
            $out .= '--'.$hash.$eol;
            $out .= 'Content-Type: '.$type.$eol;
            $out .= 'Content-Transfer-Encoding: '.$enc.$eol;
            $out .= $str.$eol;
            $out .= $message.$eol;
            foreach ($this->attachments as $attachment) {
                if (is_array($attachment['filename'])) {
                    list($alias, $file) = each($attachment);
                    $filename = $alias;
                    $attachment['filename'] = $file;
                } else {
                    $filename = basename($attachment['filename']);
                }
                $out .= '--'.$hash.$eol;
                $out .= 'Content-Type: application/octet-stream'.$eol;
                $out .= 'Content-Transfer-Encoding: base64'.$eol;
                if ($attachment['cid']) {
                    $out .= 'Content-ID: '.$attachment['cid'].$eol;
                }
                $out .= 'Content-Disposition: attachment; '.
                    'filename="'.$filename.'"'.$eol;
                $out .= $eol;
                $out .= chunk_split(base64_encode(
                    file_get_contents($attachment['filename']))).$eol;
            }
            $out .= $eol;
            $out .= '--'.$hash.'--'.$eol;
            $out .= '.';
            $this->dialog($out, false);
        } else {
            // Send mail headers
            $out = '';
            foreach ($headers as $key => $val) {
                if ($key != 'Bcc') {
                    $out .= $key.': '.$val.$eol;
                }
            }
            $out .= $eol;
            $out .= $message.$eol;
            $out .= '.';
            // Send message
            $this->dialog($out);
        }
        $this->dialog('QUIT', $log);
        if ($socket) {
            fclose($socket);
        }

        return true;
    }

    /**
     *   Instantiate class.
     *
     *   @param $host string
     *   @param $port int
     *   @param $scheme string
     *   @param $user string
     *   @param $pw string
     **/
    public function __construct($host, $port, $scheme, $user, $pw)
    {
        $this->headers = array(
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; '.
                'charset='.Base::instance()->get('ENCODING'),
        );
        $this->host = $host;
        if (strtolower($this->scheme = strtolower($scheme)) == 'ssl') {
            $this->host = 'ssl://'.$host;
        }
        $this->port = $port;
        $this->user = $user;
        $this->pw = $pw;
    }
}