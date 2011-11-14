<?php

class MemcacheSASL
{
    protected $_request_format = 'CCnCCnNNNN';
    protected $_response_format = 'Cmagic/Copcode/nkeylength/Cextralength/Cdatatype/nstatus/Nbodylength/NOpaque/NCAS1/NCAS2';

    const OPT_COMPRESSION = -1001;

    protected function _build_request($data)
    {
        $valuelength = $extralength = $keylength = 0;
        if (isset($data['extra'])) {
            $extralength = strlen($data['extra']);
        }
        if (isset($data['key'])) {
            $keylength = strlen($data['key']);
        }
        if (isset($data['value'])) {
            $valuelength = strlen($data['value']);
        }
        $bodylength = $extralength + $keylength + $valuelength;
        $ret = pack($this->_request_format, 
                0x80, 
                $data['opcode'], 
                $keylength,
                $extralength,
                $data['datatype'], 
                $data['status'], 
                $bodylength, 
                $data['Opaque'], 
                $data['CAS1'], 
                $data['CAS2']
        );

        if (isset($data['extra'])) {
            $ret .= $data['extra'];
        }

        if (isset($data['key'])) {
            $ret .= $data['key'];
        }

        if (isset($data['value'])) {
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
	if ($this->_options[self::OPT_COMPRESSION]) {
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

    public function delete($key, $time = 0)
    {
        $extra = pack('NN', 0, $expiration);
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

    public function replace($key, $value, $time = 0)
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
