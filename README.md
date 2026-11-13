# Pelican Modpack Manager

Browse, install, update and switch Minecraft modpacks directly from the Pelican server panel.

Supports **CurseForge, Modrinth, FTB and ATLauncher**, with automatic handling for **Forge, NeoForge, Fabric and Quilt**. If the server does not already have the loader egg a selected pack needs, Modpack Manager can pull the current official egg from Pelican's `pelican-eggs/minecraft` repository, switch the server to it, configure the loader/Minecraft/Java settings, install the runtime and prepare the pack automatically.

The browser also includes descriptions, galleries, project links, pagination, multi-loader/category filters, provider-failure recovery and a final install confirmation. Individual **Mods / Plugins** have the same description/gallery/project-link and paging/filter experience. Plugins can also be installed from **SpigotMC** (via the Spiget mirror; latest free, SpigotMC-hosted jar only) and **PaperMC Hangar**.

When switching packs, Modpack Manager can back up the server, clean files from the old pack, preserve player/admin data and safe server settings, optionally keep or delete the existing world, and avoid carrying old world-generation settings into the new pack. Successful installs can also use the modpack artwork as the Pelican server icon.

For the complete 1.6.9 change list and implementation details, see `NOTES.md`.

> If the panel behaves unexpectedly after installing/updating the plugin, restart the Pelican queue worker. Example on Ubuntu: `sudo systemctl restart pelican-queue`

Docker-deployed Pelican panels may not support plugins in the same way as a normal panel installation.
