<?php

namespace ipad54\BedrockEditionClient\network;

use ipad54\BedrockEditionClient\Client;
use ipad54\BedrockEditionClient\network\handler\PreSpawnPacketHandler;
use ipad54\BedrockEditionClient\network\raknet\RakNetConnection;
use ipad54\BedrockEditionClient\player\LoginInfo;
use ipad54\BedrockEditionClient\player\Player;
use ipad54\BedrockEditionClient\utils\KeyPair;
use ipad54\BedrockEditionClient\utils\Utils;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\EncryptionUtils;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\login\JwtChain;
use pocketmine\network\PacketHandlingException;
use raklib\utils\InternetAddress;

class NetworkSession{
	private const MTU = 1492;

	private InternetAddress $serverAddress;
	private LoginInfo $loginInfo;

	private Client $client;

	private RakNetConnection $connection;
	private Compressor $compressor;

	private ?EncryptionContext $cipher = null;

	private \Logger $logger;

	private PacketSerializerContext $serializerContext;
	private PacketPool $packetPool;
	private ?PacketHandler $handler = null;

	private ClientPacketSender $sender;

	private ?Player $player = null;

	private KeyPair $keyPair;

	private bool $loggedIn = false;

	public function __construct(InternetAddress $serverAddress, LoginInfo $loginInfo, Client $client){
		$this->serverAddress = $serverAddress;
		$this->loginInfo = $loginInfo;
		$this->client = $client;

		$this->logger = $client->getLogger();

		$this->compressor = ZlibCompressor::getInstance();
		$this->packetPool = PacketPool::getInstance();
		$this->serializerContext = new PacketSerializerContext(Utils::makeItemTypeDictionary());
	}

	public function getServerAddress() : InternetAddress{
		return $this->serverAddress;
	}

	public function getClient() : Client{
		return $this->client;
	}

	public function getConnection() : RakNetConnection{
		return $this->connection;
	}

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getKeyPair() : KeyPair{
		return $this->keyPair;
	}

	public function getCipher() : ?EncryptionContext{
		return $this->cipher;
	}

	public function isLoggedIn() : bool{
		return $this->loggedIn;
	}

	public function update() : void{
		$this->connection->update();
	}

	public function actuallyConnect() : void{
		$this->connection = new RakNetConnection($this, $this->logger, self::MTU);
		$this->sender = new ClientPacketSender($this->connection);
		$this->handler = new PreSpawnPacketHandler($this);
	}

	public function getHandler() : ?PacketHandler{
		return $this->handler;
	}

	public function setHandler(?PacketHandler $handler) : void{
		$this->handler = $handler;

		if($this->handler !== null){
			$this->handler->setUp();
			$this->logger->debug("A new packet handler has been set (".get_class($handler).")");
		}
	}

	public function createPlayer(StartGamePacket $packet) : void{
		if($this->player !== null){
			throw new \LogicException("Player is already created!");
		}
		$this->player = new Player($this, $this->loginInfo, $packet, $this->client->getId());

		$this->logger->debug("Player was created, eid: ".$this->client->getId());
	}

	public function startEncryption(string $handshakeJwt) : void{
		if($this->cipher !== null){
			throw new \LogicException("Encryption is already started!");
		}

		[$header, $body] = JwtUtils::parse($handshakeJwt);

		$remotePub = JwtUtils::parseDerPublicKey(base64_decode($header["x5u"]));

		$this->keyPair->setRemotePub($remotePub);

		$sharedSecret = EncryptionUtils::generateSharedSecret($this->keyPair->getLocalPriv(), $remotePub);
		$encryptionKey = EncryptionUtils::generateKey($sharedSecret, base64_decode($body["salt"]));

		$this->cipher = EncryptionContext::fakeGCM($encryptionKey);

		$this->logger->debug("Encryption was started, key: ".bin2hex($encryptionKey));
	}

	public function handleEncoded(string $payload) : void{
		if($this->cipher !== null){
			$payload = $this->cipher->decrypt($payload);
		}

		$stream = new PacketBatch($this->compressor->decompress($payload));
		foreach($stream->getPackets($this->packetPool, $this->serializerContext, 500) as [$packet, $buffer]){
			if($packet !== null){
				$this->handleDataPacket($packet, $buffer);
			}
		}
	}

	public function handleDataPacket(Packet $packet, string $buffer) : void{
		if(!($packet instanceof ClientboundPacket)){
			throw new PacketHandlingException("Unexpected non-clientbound packet");
		}

		$packet->decode(PacketSerializer::decoder($buffer, 0, $this->serializerContext));

		if($this->handler !== null){
			$packet->handle($this->handler);
		}

		foreach($this->client->getDataPacketHandlers() as $handler){
			$handler($packet);
		}

	}

	public function sendDataPacket(ServerboundPacket $packet, bool $immediate = false) : void{
		if(!$this->loggedIn && !$packet->canBeSentBeforeLogin()){
			throw new \InvalidArgumentException("Attempted to send " . get_class($packet) . " too early");
		}

		$batch = PacketBatch::fromPackets($this->serializerContext, $packet);
		$payload = $this->compressor->compress($batch->getBuffer());

		if($this->cipher !== null){
			$payload = $this->cipher->encrypt($payload);
		}

		$this->sender->send($payload, $immediate);

	}

	public function processLogin() : void{
		if($this->loggedIn){
			throw new \LogicException("Client already loggedIn!");
		}

		[$chainDataJwt, $clientDataJwt] = $this->buildLoginData();

		$this->sendDataPacket(LoginPacket::create(ProtocolInfo::CURRENT_PROTOCOL, $chainDataJwt, $clientDataJwt));

		$this->loggedIn = true;

		$this->logger->debug("LoginPacket was sent, nickname: ".$this->loginInfo->getUsername());
	}

	protected function buildLoginData() : array{
		$localPriv = openssl_pkey_new(["ec" => ["curve_name" => "secp384r1"]]);
		$localPub = JwtUtils::emitDerPublicKey($localPriv);

		$this->keyPair = new KeyPair($localPriv, $localPub);

		$localPub = base64_encode($localPub);

		$header = [
			"alg" => "ES384",
			"x5u" => $localPub
		];

		$chainDataJwt = new JwtChain();
		$chainDataJwt->chain = [JwtUtils::create($header, [
			"exp" => time() + 3600,
			"extraData" => [
				"XUID" => "", //TODO: Xbox auth
				"displayName" => $this->loginInfo->getUsername(),
				"identity" => $this->loginInfo->getUuid()->toString(),
			],
			"identityPublicKey" => $localPub,
			"nbf" => time() - 3600
		], $localPriv)];

		$skin = $this->loginInfo->getSkin();

		$clientDataJwt = JwtUtils::create($header, [
			"AnimatedImageData" => [], //TODO: Hardcoded value
			"ArmSize" => "wide", //TODO: Hardcoded value
			"CapeData" => "", //TODO: Hardcoded value
			"CapeId" => "", //TODO: Hardcoded value
			"CapeImageHeight" => 0, //TODO: Hardcoded value
			"CapeImageWidth" => 0, //TODO: Hardcoded value
			"CapeOnClassicSkin" => false, //TODO: Hardcoded value
			"ClientRandomId" => $this->client->getId(),
			"CurrentInputMode" => 2, //TODO: Hardcoded value
			"DefaultInputMode" => 1, //TODO: Hardcoded value
			"DeviceId" => $this->loginInfo->getDeviceId(),
			"DeviceModel" => $this->loginInfo->getDeviceModel(),
			"DeviceOS" => $this->loginInfo->getDeviceOS(),
			"GameVersion" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
			"GuiScale" => 0, //TODO: Hardcoded value
			"LanguageCode" => $this->loginInfo->getLocale(),
			"PersonaPieces" => [], //TODO: Hardcoded value
			"PersonaSkin" => false, //TODO: Hardcoded value
			"PieceTintColors" => [], //TODO: Hardcoded value
			"PlatformOfflineId" => "", //TODO: Hardcoded value
			"PlatformOnlineId" => "", //TODO: Hardcoded value
			"PlayFabId" => "f79a424e50f4736", //TODO: Hardcoded value
			"PremiumSkin" => false, //TODO: Hardcoded value
			"SelfSignedId" => base64_encode(random_bytes(16)),
			"ServerAddress" => $this->serverAddress->getIp() . ":" . $this->serverAddress->getPort(),
			"SkinAnimationData" => "", //TODO: Hardcoded value
			"SkinColor" => "#b37b62", //TODO: Hardcoded value
			"SkinData" => base64_encode($skin->getSkinData()),
			"SkinGeometryData" => "", //TODO: Hardcoded value
			"SkinGeometryDataEngineVersion" => "MS4xNC4w", //TODO: Hardcoded value
			"SkinId" => $skin->getSkinId(),
			"SkinImageHeight" => 64, //TODO: Hardcoded value
			"SkinImageWidth" => 64, //TODO: Hardcoded value
			"SkinResourcePatch" => base64_encode(json_encode(["geometry" => ["default" => "geometry.humanoid.custom"]])),
			"ThirdPartyName" => "", //TODO: Hardcoded value
			"ThirdPartyNameOnly" => false, //TODO: Hardcoded value
			"UIProfile" => 1 //TODO: Hardcoded value
		], $localPriv);

		return [$chainDataJwt, $clientDataJwt];
	}
}