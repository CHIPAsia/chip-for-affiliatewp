=== CHIP for AffiliateWP ===
Contributors: chipasia, wanzulnet
Tags: chip
Requires at least: 4.7
Tested up to: 6.6
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Digital Finance Platform. Securely make payment to referrer with CHIP Send.

== Description ==

This is an official CHIP plugin for AffiliateWP.

CHIP is a comprehensive Digital Finance Platform specifically designed to support and empower Micro, Small and Medium Enterprises (MSMEs). We provide a suite of solutions encompassing payment collection, expense management, risk mitigation, and treasury management.

Our aim is to help businesses streamline their financial processes, reduce operational complexity, and drive growth.

With CHIP, you gain a financial partner committed to simplifying, digitizing, and enhancing your financial operations for ultimate success.

This plugin will enable your AffiliateWP site to be integrated with CHIP Send as per documented in [API Documentation](https://docs.chip-in.asia).

== Screenshots ==
* Will be added later

== Changelog ==

= 1.0.0 2024-11-XX =
* Added - Initial release.

[See changelog for all versions](https://raw.githubusercontent.com/CHIPAsia/chip-for-affiliatewp/main/changelog.txt).

== Installation ==

= Minimum Requirements =

* PHP 7.4 or greater is required (PHP 8.0 or greater is recommended)
* MySQL 5.6 or greater, OR MariaDB version 10.1 or greater, is required

= Manual installation =

Manual installation method requires downloading the CHIP for AffiliateWP plugin and uploading it to your web server via your favorite FTP application. The WordPress codex contains [instructions on how to do this here](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation).

== Frequently Asked Questions ==

= Where is the API Key and API Secret located? =

API Key and API Secret available through our merchant dashboard.

= Where can I find documentation? =

You can visit our [API documentation](https://docs.chip-in.asia/) for your reference.

= What CHIP API services used in this plugin? =

This plugin rely on CHIP API ([AFWP_CHIP_ROOT_URL](https://api.chip-in.asia/api/send)) as follows:

  - **/account/**
    - This is for getting available CHIP Send balance
  - **/bank_accounts/**
    - This is for registering bank account
  - **/send_instructions/**
    - This is for sending payment to bank account

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)