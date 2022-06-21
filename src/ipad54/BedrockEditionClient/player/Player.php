<?php

namespace ipad54\BedrockEditionClient\player;

use ipad54\BedrockEditionClient\network\NetworkSession;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use Ramsey\Uuid\UuidInterface;

class Player{

	private NetworkSession $networkSession;
	private LoginInfo $loginInfo;
	private UuidInterface $uuid;
	private StartGamePacket $startGamePacket;
	private Skin $skin;

	private string $username;

	private int $id;

	private bool $spawned = false;

	public function __construct(NetworkSession $networkSession, LoginInfo $loginInfo, StartGamePacket $startGamePacket, int $id){
		$this->networkSession = $networkSession;
		$this->loginInfo = $loginInfo;
		$this->uuid = $loginInfo->getUuid();
		$this->skin = $loginInfo->getSkin();

		$this->username = $loginInfo->getUsername();

		$this->startGamePacket = $startGamePacket;

		$this->id = $id;
	}

	public function getLoginInfo() : LoginInfo{
		return $this->loginInfo;
	}

	public function getNetworkSession() : NetworkSession{
		return $this->networkSession;
	}

	public function getUuid() : UuidInterface{
		return $this->uuid;
	}

	public function getStartGameInfo() : StartGamePacket{
		return $this->startGamePacket;
	}

	public function getSkin() : Skin{
		return $this->skin;
	}

	public function getUsername() : string{
		return $this->username;
	}

	public function getId() : int{
		return $this->id;
	}

	public function isSpawned() : bool{
		return $this->spawned;
	}

	public function setSpawned(bool $spawned) : void{
		$this->spawned = $spawned;
	}
}