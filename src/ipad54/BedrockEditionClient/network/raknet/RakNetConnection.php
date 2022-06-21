<?php

namespace ipad54\BedrockEditionClient\network\raknet;

use ipad54\BedrockEditionClient\network\NetworkSession;
use raklib\generic\ReceiveReliabilityLayer;
use raklib\generic\SendReliabilityLayer;
use raklib\generic\Socket;
use raklib\protocol\ACK;
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\ConnectedPacket;
use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectedPong;
use raklib\protocol\ConnectionRequest;
use raklib\protocol\ConnectionRequestAccepted;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NACK;
use raklib\protocol\NewIncomingConnection;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\Packet;
use raklib\protocol\PacketReliability;
use raklib\protocol\PacketSerializer;
use raklib\utils\InternetAddress;

class RakNetConnection{ //

	public const MAX_SPLIT_PART_COUNT = 128;
	public const MAX_CONCURRENT_SPLIT_COUNT = 4;

	public const STATE_CONNECTING = 0;
	public const STATE_CONNECTED = 1;

	public const MIN_MTU_SIZE = 400;

	public const MCPE_RAKNET_PACKET_ID = "\xfe";

	private NetworkSession $networkSession;

	private InternetAddress $serverAddress;

	private Socket $socket;

	private ReceiveReliabilityLayer $recvLayer;
	private SendReliabilityLayer $sendLayer;

	private \Logger $logger;

	private int $mtuSize;

	private int $lastUpdate;
	private int $startTimeMS;

	private int $state = self::STATE_CONNECTING;

	private bool $offline = true;

	public function __construct(NetworkSession $networkSession, \Logger $logger, int $mtuSize){
		if($mtuSize < self::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . self::MIN_MTU_SIZE . ", got $mtuSize");
		}
		$this->networkSession = $networkSession;

		$this->serverAddress = $this->networkSession->getServerAddress();

		$this->logger = $logger;

		$this->mtuSize = $mtuSize;

		$this->lastUpdate = time();
		$this->startTimeMS = (int) (microtime(true) * 1000);

		$this->socket = new Socket(new InternetAddress("0.0.0.0", mt_rand(1, 65535), 4));

		$this->recvLayer = new ReceiveReliabilityLayer(
			$this->logger,
			function(EncapsulatedPacket $pk) : void{
				$this->handleEncapsulatedPacketRoute($pk);
			},
			function(AcknowledgePacket $pk) : void{
				$this->sendPacket($pk);
			},
			self::MAX_SPLIT_PART_COUNT,
			self::MAX_CONCURRENT_SPLIT_COUNT
		);
		$this->sendLayer = new SendReliabilityLayer(
			$mtuSize,
			function(Datagram $datagram) : void{
				$this->sendPacket($datagram);
			},
			function(int $identifierACK) : void{
			}
		);

		$pk = new OpenConnectionRequest1();
		$pk->protocol = 10;
		$pk->mtuSize = $this->mtuSize - 28;
		$this->sendPacket($pk);

		$this->logger->debug("Sending OpenConnectionRequest1");
	}

	public function getRakNetTimeMS() : int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	public function update() : void{
		$this->receivePacket();

		$this->recvLayer->update();
		$this->sendLayer->update();

		if((time() - $this->lastUpdate) >= 7){
			$this->sendPing();
			$this->lastUpdate = time();
		}
	}

	public function receivePacket() : void{
		if(($buffer = $this->socket->readPacket($ip, $port)) !== null){
			if($this->offline){
				$pk = OfflinePacketPool::getInstance()->getPacketFromPool($buffer);
				if($pk !== null){
					$reader = new PacketSerializer($buffer);
					$pk->decode($reader);

					if($pk->isValid()){
						$this->handleOfflineMessage($pk);
					}
				}
			}else{
				$header = ord($buffer[0]);
				if(($header & Datagram::BITFLAG_VALID) !== 0){
					if(($header & Datagram::BITFLAG_ACK) !== 0){
						$packet = new ACK();
					}elseif(($header & Datagram::BITFLAG_NAK) !== 0){
						$packet = new NACK();
					}else{
						$packet = new Datagram();
					}
					$packet->decode(new PacketSerializer($buffer));
					$this->handlePacket($packet);
				}
			}
		}
	}

	public function handlePacket(Packet $packet) : void{
		if($packet instanceof Datagram){
			$this->recvLayer->onDatagram($packet);
		}elseif($packet instanceof ACK){
			$this->sendLayer->onACK($packet);
		}elseif($packet instanceof NACK){
			$this->sendLayer->onNACK($packet);
		}
	}

	public function handleOfflineMessage(OfflineMessage $pk) : void{
		if($pk instanceof OpenConnectionReply1){
			$pk = new OpenConnectionRequest2();
			$pk->clientID = $this->networkSession->getClient()->getId();
			$pk->serverAddress = $this->serverAddress;
			$pk->mtuSize = $this->mtuSize;
			$this->sendPacket($pk);

			$this->logger->debug("Sending OpenConnectionRequest2");
		}elseif($pk instanceof OpenConnectionReply2){
			$pk = new ConnectionRequest();
			$pk->clientID = $this->networkSession->getClient()->getId();
			$pk->sendPingTime = time() + 20;
			$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0);

			$this->offline = false;

			$this->logger->debug("Sending ConnectionRequest");
		}
	}

	private function queueConnectedPacket(ConnectedPacket $packet, int $reliability, int $orderChannel, bool $immediate = false) : void{
		$out = new PacketSerializer();
		$packet->encode($out);

		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = $reliability;
		$encapsulated->orderChannel = $orderChannel;
		$encapsulated->buffer = $out->getBuffer();

		$this->sendLayer->addEncapsulatedToQueue($encapsulated, $immediate);
	}

	public function sendPacket(Packet $packet) : void{
		$out = new PacketSerializer();
		$packet->encode($out);
		$this->socket->writePacket($out->getBuffer(), $this->serverAddress->getIp(), $this->serverAddress->getPort());
	}

	private function sendPing() : void{
		$this->queueConnectedPacket(ConnectedPing::create($this->getRakNetTimeMS()), PacketReliability::UNRELIABLE, 0, true);
	}

	private function sendPong(int $sendPongTime) : void{
		$this->queueConnectedPacket(ConnectedPong::create($sendPongTime, $this->getRakNetTimeMS()), PacketReliability::UNRELIABLE, true);
	}

	public function sendEncapsulated(EncapsulatedPacket $packet, bool $immediate = false) : void{
		$this->sendLayer->addEncapsulatedToQueue($packet, $immediate);
	}

	public function sendRaw(string $payload) : void{
		$this->socket->writePacket($payload, $this->serverAddress->getIp(), $this->serverAddress->getPort());
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		$id = ord($packet->buffer[0]);
		if($id < MessageIdentifiers::ID_USER_PACKET_ENUM){ //internal data packet
			if($this->state === self::STATE_CONNECTING){
				if($id === ConnectionRequestAccepted::$ID){
					$pk = new NewIncomingConnection();
					$pk->address = $this->serverAddress;
					for($i = 0; $i < 10; ++$i){
						$pk->systemAddresses[$i] = $pk->address;
					}
					$pk->sendPingTime = $pk->sendPongTime = 0;
					$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0);

					$this->networkSession->processLogin();

					$this->sendPing();

					$this->state = self::STATE_CONNECTED;

					$this->logger->debug("Connection accepted");
				}
			}elseif($id === ConnectedPong::$ID){
				$pk = new ConnectedPong();
				$pk->decode(new PacketSerializer($packet->buffer));
				$this->sendPong($pk->sendPongTime);
			}
		}elseif($this->state === self::STATE_CONNECTED){
			$buffer = $packet->buffer;
			if($buffer !== "" && $buffer[0] === self::MCPE_RAKNET_PACKET_ID){
				$buffer = substr($buffer, 1);

				$this->networkSession->handleEncoded($buffer);
			}
		}else{
			//$this->logger->notice("Received packet before connection: " . bin2hex($packet->buffer));
		}
	}
}