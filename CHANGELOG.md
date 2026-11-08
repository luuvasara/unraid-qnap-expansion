# Changelog

Copied by the build from the <CHANGES> block of qnap-expansion.plg; edit the .plg, not this file.

## 2026.11.08a
- First version. Utilities, QNAP Expansion, for a QNAP TL-D1600S on its QXP-1600eS-A1164 card.
- Status tab: whether the card is found (by its PCI ids and QNAP subsystem), runs on the standard ahci driver, its PCIe links run at the speed and width both ends can do, its PCIe error counters, the SATA link of each drive, and whether the cabling is confirmed. What the server cannot reach of the enclosure (fans, LEDs, firmware) is said plainly.
- Enclosures tab: a front view as the bays are labelled (HDD1-HDD12, SSD1-SSD4) with the drive in each bay and its Unraid slot, temperature and state as Unraid last read them (no drive is woken); the bay table with SMART health and Locate; the PCIe links.
- Locate reads one drive for 20 seconds so its activity light flashes. It only reads, and asks first when the drive is in standby.
- Cabling: which controller feeds which group of bays depends on how the four cables are plugged. The bays follow a measured unit until you confirm yours with Locate; the confirmation is kept on the flash drive.
