<?php
/**
 * The newest version, as the store described it.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Models;

/**
 * What a `latest` answer says about the newest version, already checked by
 * Store\Reader: every value here is one it let through, so nothing that
 * reads it checks again.
 */
final readonly class Release {

	/**
	 * Set up.
	 *
	 * @param string                $version        The version; '' when the store named none.
	 * @param bool|null             $update         The store's own word on whether it is newer.
	 * @param string                $package        The download link: https, on the store's host, or ''.
	 * @param string                $changelog      Plain text.
	 * @param string                $name           Plain text.
	 * @param string                $homepage       An http(s) address, or ''.
	 * @param string                $requires       A version, or ''.
	 * @param string                $tested         A version, or ''.
	 * @param string                $requires_php   A version, or ''.
	 * @param string                $last_updated   Plain text.
	 * @param string                $upgrade_notice Plain text.
	 * @param array<string, string> $sections       HTML, by tab name (kses'd when shown).
	 * @param array<string, string> $icons          Addresses, by size.
	 * @param array<string, string> $banners        Addresses, by size.
	 * @param array<int, array{name: string, url: string, expires_at: int|null}> $files Every file, each link held to the package's rule.
	 */
	public function __construct(
		public string $version = '',
		public ?bool $update = null,
		public string $package = '',
		public string $changelog = '',
		public string $name = '',
		public string $homepage = '',
		public string $requires = '',
		public string $tested = '',
		public string $requires_php = '',
		public string $last_updated = '',
		public string $upgrade_notice = '',
		public array $sections = [],
		public array $icons = [],
		public array $banners = [],
		public array $files = [],
	) {
	}

	/**
	 * The keys a `latest` answer carries, with what is set.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array_filter(
			[
				'version'        => '' !== $this->version ? $this->version : null,
				'update'         => $this->update,
				'package'        => '' !== $this->package ? $this->package : null,
				'changelog'      => $this->changelog,
				'name'           => $this->name,
				'homepage'       => $this->homepage,
				'requires'       => $this->requires,
				'tested'         => $this->tested,
				'requires_php'   => $this->requires_php,
				'last_updated'   => $this->last_updated,
				'upgrade_notice' => $this->upgrade_notice,
				'sections'       => $this->sections,
				'icons'          => $this->icons,
				'banners'        => $this->banners,
				'files'          => $this->files,
			],
			static fn( mixed $value ): bool => null !== $value && '' !== $value && [] !== $value
		);
	}
}
