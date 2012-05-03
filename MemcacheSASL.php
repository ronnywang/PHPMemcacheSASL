<?php

class MemcacheSASL
{
    protected $_request_format = 'CCnCCnNNNN';
    protected $_response_format = 'Cmagic/Copcode/nkeylength/Cextralength/Cdatatype/nstatus/Nbodylength/NOpaque/NCAS1/NCAS2';

    const OPT_COMPRESSION = -1001;

    protected function _build_request($data)
    {
        $valuelength = $extralength = $keylength = 0;
        if (array_key_exists('extra', $data)) {
            $extralength = strlen($data['extra']);
        }
        if (array_key_exists('key', $data)) {
            $keylength = strlen($data['key']);
        }
        if (array_key_exists('value', $data)) {
            $valuelength = strlen($data['value']);
        }
        $bodylength = $extralength + $keylength + $valuelength;
        $ret = pack($this->_request_format, 
                0x80, 
                $data['opcode'], 
                $keylength,
                $extralength,
                array_key_exists('datatype', $data) ? $data['datatype'] : null,
                array_key_exists('status', $data) ? $data['status'] : null,
                $bodylength, 
                array_key_exists('Opaque', $data) ? $data['Opaque'] : null,
                array_key_exists('CAS1', $data) ? $data['CAS1'] : null,
                array_key_exists('CAS2', $data) ? $data['CAS2'] : null
            );

        if (array_key_exists('extra', $data)) {
            $ret .= $data['extra'];
        }

        if (array_key_exists('key', $data)) {
            $ret .= $data['key'];
        }

        if (array_key_exists('value', $data)) {
            $ret .= $data['value'];
        }
        return $ret;
    }

    protected function _show_request($data)
    {
        $array = unpack($this->_response_format, $data);
        return $array;
    }

    protected function _send($data)
    {
        $send_data = $this->_build_request($data);
        fwrite($this->_fp, $send_data);
        return $send_data;
    }

    protected function _recv()
    {
        $data = fread($this->_fp, 24);
        $array = $this->_show_request($data);
	if ($array['bodylength']) {
	    $bodylength = $array['bodylength'];
	    $data = '';
	    while ($bodylength > 0) {
		$recv_data = fread($this->_fp, $bodylength);
		$bodylength -= strlen($recv_data);
		$data .= $recv_data;
	    }

	    if ($array['extralength']) {
		$extra_unpacked = unpack('Nint', substr($data, 0, $array['extralength']));
		$array['extra'] = $extra_unpacked['int'];
	    }
	    $array['key'] = substr($data, $array['extralength'], $array['keylength']);
	    $array['body'] = substr($data, $array['extralength'] + $array['keylength']);
	}
        return $array;
    }

    public function __construct()
    {
    }


    public function listMechanisms()
    {
        $this->_send(array('opcode' => 0x20));
        $data = $this->_recv();
        return explode(" ", $data['body']);
    }

    public function setSaslAuthData($user, $password)
    {
        $this->_send(array(
                    'opcode' => 0x21,
                    'key' => 'PLAIN',
                    'value' => '' . chr(0) . $user . chr(0) . $password
                    ));
        $data = $this->_recv();

        if ($data['status']) {
            throw new Exception($data['body'], $data['status']);
        }
    }

    public function addServer($host, $port, $weight = 0)
    {
        $this->_fp = stream_socket_client($host . ':' . $port);
    }

    public function get($key)
    {   
        $sent = $this->_send(array(
                    'opcode' => 0x00,
                    'key' => $key,
                    ));
	$data = $this->_recv();
	if (0 == $data['status']) {
	    if (16 == $data['extra']) {
		return gzuncompress($data['body']);
	    } else {
		return $data['body'];
	    }
        }
        return FALSE;
    }

    public function add($key, $value, $expiration = 0)
    {
	$flag = 0;
	if ($this->_options[self::OPT_COMPRESSION]) {
	    $flag = 16;
	    $value = gzcompress($value);
	}
        $extra = pack('NN', $flag, $expiration);
        $sent = $this->_send(array(
                    'opcode' => 0x02,
                    'key' => $key,
                    'value' => $value,
                    'extra' => $extra,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    public function set($key, $value, $expiration = 0)
    {
	$flag = 0;
        if (array_key_exists(self::OPT_COMPRESSION, $this->_options) && $this->_options[self::OPT_COMPRESSION]) {
	    $flag = 16;
	    $value = gzcompress($value);
	}
        $extra = pack('NN', $flag, $expiration);
        $sent = $this->_send(array(
                    'opcode' => 0x01,
                    'key' => $key,
                    'value' => $value,
                    'extra' => $extra,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    public function delete($key)
    {
        $sent = $this->_send(array(
                    'opcode' => 0x04,
                    'key' => $key,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    public function replace($key, $value, $expiration = 0)
    {
	$flag = 0;
	if ($this->_options[self::OPT_COMPRESSION]) {
	    $flag = 16;
	    $value = gzcompress($value);
	}
        $extra = pack('NN', $flag, $expiration);
        $sent = $this->_send(array(
                    'opcode' => 0x03,
                    'key' => $key,
                    'value' => $value,
                    'extra' => $extra,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    protected function _upper($num)
    {
        return $num << 32;
    }

    protected function _lower($num)
    {
        return $num % (2 << 32);
    }

    public function increment($key, $offset = 1)
    {
        $initial_value = 0;
        $extra = pack('N2N2N', $this->_upper($offset), $this->_lower($offset), $this->_upper($initial_value), $this->_lower($initial_value), $expiration);
        $sent = $this->_send(array(
                    'opcode' => 0x05,
                    'key' => $key,
                    'extra' => $extra,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    public function decrement($key, $offset = 1)
    {
        $initial_value = 0;
        $extra = pack('N2N2N', $this->_upper($offset), $this->_lower($offset), $this->_upper($initial_value), $this->_lower($initial_value), $expiration);
        $sent = $this->_send(array(
                    'opcode' => 0x06,
                    'key' => $key,
                    'extra' => $extra,
                    ));
        $data = $this->_recv();
        if ($data['status'] == 0) {
            return TRUE;
        }

        return FALSE;
    }

    public function append()
    {
    }

    public function prepend()
    {
    }

    public function getMulti()
    {
    }


    protected $_options = array();

    public function setOption($key, $value)
    {
	$this->_options[$key] = $value;
    }
}
