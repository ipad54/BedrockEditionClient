<?php

namespace ipad54\BedrockEditionClient\network;

use ipad54\BedrockEditionClient\network\raknet\RakNetConnection;
use pocketmine\network\mcpe\PacketSender;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;

class ClientPacketSender implements PacketSender{

	public function __construct(private RakNetConnection $connection){}


	public function send(string $payload, bool $immediate) : void{
		$pk = new EncapsulatedPacket();
		$pk->buffer = RakNetConnection::MCPE_RAKNET_PACKET_ID . $payload;
		$pk->reliability = PacketReliability::RELIABLE_ORDERED;
		$pk->orderChannel = 0;

		$this->connection->sendEncapsulated($pk, $immediate);
	}

	public function close(string $reason = "unknown reason") : void{
		// TODO: Implement close() method.
	}
}