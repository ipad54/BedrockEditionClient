<?php

namespace ipad54\BedrockEditionClient\utils;

final class KeyPair{

	public function __construct(private \OpenSSLAsymmetricKey $localPriv, private string $localPub, private ?\OpenSSLAsymmetricKey $remotePub = null){}

	public function getLocalPriv() : \OpenSSLAsymmetricKey{
		return $this->localPriv;
	}

	public function getLocalPub() : string{
		return $this->localPub;
	}

	public function getRemotePub() : ?\OpenSSLAsymmetricKey{
		return $this->remotePub;
	}

	public function setRemotePub(?\OpenSSLAsymmetricKey $remotePub) : void{
		$this->remotePub = $remotePub;
	}
}