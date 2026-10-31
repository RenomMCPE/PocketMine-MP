<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the Altay Team.
 * https://github.com/altayofficial
 *
 * Adapted for multiversion protocol support.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\utils\SingletonTrait;

/**
 * Remembers the encoded bytes of packets that never change once built, like the crafting data every player gets on
 * login. Encoding that one alone was a fifth of the CPU spent letting a crowd of players in.
 *
 * Only packets passed to markImmutable() are cached, and the entry goes away with the packet object, so a cache that
 * rebuilds its packet (e.g. after a new recipe is registered) gets fresh bytes without any extra work.
 *
 * Unlike the original Altay implementation, entries are keyed by protocol ID as well as the packet object: this
 * server supports multiple protocol versions, and the same packet object may need a different encoding for each one.
 */
final class EncodedPacketCache{
	use SingletonTrait;

	/**
	 * false means marked but not encoded yet. Not null, since WeakMap's isset() and offsetExists() both treat a null
	 * value as missing
	 * @phpstan-var \WeakMap<ClientboundPacket, array<int, string|false>>
	 */
	private \WeakMap $packets;

	public function __construct(){
		$this->packets = new \WeakMap();
	}

	/**
	 * Promise that this packet object won't be modified anymore. Breaking that promise means players get stale data.
	 */
	public function markImmutable(ClientboundPacket $packet) : void{
		if(!isset($this->packets[$packet])){
			$this->packets[$packet] = [];
		}
	}

	public function get(ClientboundPacket $packet, int $protocolId) : ?string{
		$encoded = $this->packets[$packet][$protocolId] ?? false;
		return $encoded === false ? null : $encoded;
	}

	/**
	 * Stores the encoded form if the packet was marked as immutable, otherwise does nothing.
	 */
	public function remember(ClientboundPacket $packet, int $protocolId, string $encoded) : void{
		if(isset($this->packets[$packet])){
			$this->packets[$packet][$protocolId] = $encoded;
		}
	}
}
