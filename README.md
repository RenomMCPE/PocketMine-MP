<p align="center">
	<b>PocketMine-MP (RenomMCPE)</b><br>
	<b>A multiversion server software for Minecraft: Bedrock Edition written in PHP</b>
</p>

<p align="center">
	<a href="https://github.com/RenomMCPE/PocketMine-MP/actions/workflows/ci.yml"><img src="https://github.com/RenomMCPE/PocketMine-MP/actions/workflows/ci.yml/badge.svg" alt="CI" /></a>
	<a href="https://github.com/RenomMCPE/PocketMine-MP/releases/latest"><img alt="GitHub release" src="https://img.shields.io/github/v/release/RenomMCPE/PocketMine-MP?sort=semver"></a>
</p>

## What is this?
This is a multiversion fork of PocketMine-MP, a highly customisable server software for Minecraft: Bedrock Edition written in PHP. Players on a wide range of Bedrock versions — from **1.20.0 up to 1.26.60** — can connect to the same server.

It uses the open-source codebase of [NetherGamesMC/PocketMine-MP](https://github.com/NetherGamesMC/PocketMine-MP) as its foundation, which in turn builds on [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP), the original project. Selective lightweight performance optimizations have been ported from the [Amber](https://github.com/Amber-PM/Amber) and [Altay](https://github.com/altayofficial/Altay) forks.

- 🌐 **Multiversion support** - clients on many Bedrock protocol versions can join the same server
- 🧩 **Powerful plugin API** - extend and customise gameplay as you see fit
- 🗺️ **Multi-world support** - offer a more varied game experience to players without transferring them to other server nodes
- 🏎️ **Performance** - get 100+ players onto one server (depending on hardware and plugins)

## :x: PocketMine-MP is NOT a vanilla Minecraft server software.
**It is poorly suited to hosting vanilla survival servers.**
It doesn't have many features from the vanilla game, such as vanilla world generation, redstone, mob AI, and various other things.

If you just want to play **vanilla survival multiplayer**, consider using the [official Minecraft: Bedrock server software](https://minecraft.net/download/server/bedrock) instead of PocketMine-MP.

If that's not an option for you, you may be able to add some of PocketMine-MP's missing features using plugins from [Poggit](https://poggit.pmmp.io/plugins), or write plugins to implement them yourself.

## Getting Started
Download the latest `.phar` from the [Releases](https://github.com/RenomMCPE/PocketMine-MP/releases) page and run it with a [PocketMine-MP PHP binary](https://github.com/pmmp/PHP-Binaries/releases) for your platform:

```
php PocketMine-MP.phar
```

- [Documentation](http://pmmp.readthedocs.org/)
- [Installation instructions](https://pmmp.readthedocs.io/en/rtfd/installation.html)
- [Plugin repository](https://poggit.pmmp.io/plugins)

### Network transports
This server supports the classic **RakLib (RakNet)** transport out of the box with the official PocketMine PHP binaries. It additionally supports the **NetherNet (WebRTC)** transport, which requires a PHP build containing `ext-webrtc`; without it, the server automatically falls back to RakLib at startup.

## Building from source
See [BUILDING.md](BUILDING.md). In short:

```
composer install
php build/server-phar.php
```

Note: `ext-webrtc` is not part of the official PHP binaries; use `composer install --ignore-platform-req=ext-webrtc` if your build lacks it (RakLib works without it).

## Credits
This project would not exist without the following projects:

- [NetherGamesMC/PocketMine-MP](https://github.com/NetherGamesMC/PocketMine-MP) - the open-source multiversion codebase this server is built on
- [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP) - the original PocketMine-MP, created and maintained for over 10 years by the PocketMine team
- [Altay](https://github.com/altayofficial/Altay) - source of the encoded-packet cache optimization (adapted for multiversion protocol keying)
- [Amber](https://github.com/Amber-PM/Amber) - source of the weak-reference network flush optimization

## Licensing information
This project is licensed under LGPL-3.0. Please see the [LICENSE](/LICENSE) file for details.

PocketMine-MP is not affiliated with Mojang. All brands and trademarks belong to their respective owners. PocketMine-MP is not a Mojang-approved software, nor is it associated with Mojang.
