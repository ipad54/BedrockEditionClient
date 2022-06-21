<?php

namespace ipad54\BedrockEditionClient\network\raknet;

use pocketmine\utils\SingletonTrait;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;

final class OfflinePacketPool{
	use SingletonTrait;

	private \SplFixedArray $packetPool;

	public function __construct(){
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionReply1::$ID, OpenConnectionReply1::class);
		$this->registerPacket(OpenConnectionReply2::$ID, OpenConnectionReply2::class);
	}

	private function registerPacket(int $id, string $class) : void{
		$this->packetPool[$id] = new $class;
	}

	public function getPacketFromPool(string $buffer) : ?OfflineMessage{
		$pk = $this->packetPool[ord($buffer[0])];
		if($pk !== null){
			return clone $pk;
		}

		return null;
	}
}