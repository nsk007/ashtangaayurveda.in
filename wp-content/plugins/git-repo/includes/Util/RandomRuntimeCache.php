<?php
/**
 * A runtime object cache for storing and randomly retrieving reusable data.
 *
 * @package SmoothGenerator\Util
 */

namespace WC\SmoothGenerator\Util;

/**
 * Class RandomRuntimeCache.
 */
class RandomRuntimeCache {
	/**
	 * Associative array for storing groups of cache items.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Check if a specific cache group exists.
	 *
	 * @param string $group The specified cache group.
	 *
	 * @return bool
	 */
	public static function exists( string $group ): bool {
		return array_key_exists( $group, self::$cache );
	}

	/**
	 * Get a number of items from a specific cache group.
	 *
	 * The retrieved items will be from the top of the group's array.
	 *
	 * @param string $group The specified cache group.
	 * @param int    $limit Optional. Get up to this many items. Using 0 will return all the items in the group.
	 *                      Default 0.
	 *
	 * @return array
	 */
	public static function get( string $group, int $limit = 0 ): array {
		$all_items = self::get_group( $group );

		if ( $limit <= 0 || count( $all_items ) <= $limit ) {
			return $all_items;
		}

		$items = array_slice( $all_items, 0, $limit );

		return $items;
	}

	/**
	 * Remove a number of items from a specific cache group and return them.
	 *
	 * The items will be extracted from the top of the group's array.
	 *
	 * @param string $group The specified cache group.
	 * @param int    $limit Optional. Extract up to this many items. Using 0 will return all the items in the group and
	 *                      delete it from the cache. Default 0.
	 *
	 * @return array
	 */
	public static function extract( string $group, int $limit = 0 ): array {
		$all_items = self::get_group( $group );

		if ( $limit <= 0 || count( $all_items ) <= $limit ) {
			self::clear( $group );

			return $all_items;
		}

		$items           = array_slice( $all_items, 0, $limit );
		$remaining_items = array_slice( $all_items, $limit );

		self::set( $group, $remaining_items );

		return $items;
	}

	/**
	 * Add items to a specific cache group.
	 *
	 * @param string $group The specified cache group.
	 * @param array  $items The items to add to the group.
	 *
	 * @return void
	 */
	public static function add( string $group, array $items ): void {
		$existing_items = self::get_group( $group );

		self::set( $group, array_merge( $existing_items, $items ) );
	}

	/**
	 * Set a cache group to contain a specific set of items.
	 *
	 * @param string $group The specified cache group.
	 * @param array  $items The items that will be in the group.
	 *
	 * @return void
	 */
	public static function set( string $group, array $items ): void {
		self::$cache[ $group ] = $items;
	}

	/**
	 * Count the number of items in a specific cache group.
	 *
	 * @param string $group The specified cache group.
	 *
	 * @return int
	 */
	public static function count( string $group ): int {
		$group = self::get_group( $group );

		return count( $group );
	}

	/**
	 * Shuffle the order of the items in a specific cache group.
	 *
	 * @param string $group The specified cache group.
	 *
	 * @return void
	 */
	public static function shuffle( string $group ): void {
		// Ensure group exists.
		self::get_group( $group );

		shuffle( self::$cache[ $group ] );
	}

	/**
	 * Delete a group from the cache.
	 *
	 * @param string $group The specified cache group.
	 *
	 * @return void
	 */
	public static function clear( string $group ): void {
		unset( self::$cache[ $group ] );
	}

	/**
	 * Clear the entire cache.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = array();
	}

	/**
	 * Get the items in a cache group, ensuring that the group exists in the cache.
	 *
	 * @param string $group The specified cache group.
	 *
	 * @return array
	 */
	private static function get_group( string $group ): array {
		if ( ! self::exists( $group ) ) {
			self::set( $group, array() );
		}

		return self::$cache[ $group ];
	}
}
