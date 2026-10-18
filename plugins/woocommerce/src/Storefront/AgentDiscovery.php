<?php

namespace LetAgents\Storefront;

use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * How an AI agent that reaches the store finds what Let Agents keeps for it, without anyone giving it
 * a link: at the store's own address,
 *
 *   /llms.txt                          the store for a language model: what it sells, its catalogue for
 *                                      AI, its MCP server, its policies and categories
 *   /.well-known/mcp/server-card.json  the store's MCP server, described (MCP Server Card, SEP-2127 draft)
 *   /.well-known/mcp.json              the same, where an agent looks for it under the older proposal
 *
 * and two links in every page's head. Served from Let Agents and kept here for six hours, so an agent
 * never waits on it twice. Only while Lets Search is live. A file of the store's own (an llms.txt an SEO
 * plugin wrote) is served by the web server before WordPress, so it is never replaced. Read-only.
 */
final class AgentDiscovery {

	private const CACHE_SECONDS = 6 * HOUR_IN_SECONDS;

	/** @var array<string, array{0: string, 1: string}> path => [Let Agents path, content type] */
	private const FILES = array(
		'llms.txt'                         => array( 'search/%s/llms.txt', 'text/markdown; charset=utf-8' ),
		'.well-known/mcp/server-card.json' => array( 'mcp/%s/server-card', 'application/json; charset=utf-8' ),
		'.well-known/mcp.json'             => array( 'mcp/%s/server-card', 'application/json; charset=utf-8' ),
	);

	public static function register(): void {
		add_action( 'init', array( self::class, 'serve' ), 1 );
		add_action( 'wp_head', array( self::class, 'head' ), 4 );
	}

	public static function serve(): void {
		$file = self::requested();

		if ( null === $file || ! self::on() ) {
			return;
		}

		$body = self::fetch( $file );

		// Let Agents could not be reached and nothing is kept: WordPress answers as it would.
		if ( null === $body ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: ' . self::FILES[ $file ][1] );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text and JSON served as they are.
		exit;
	}

	public static function head(): void {
		if ( ! self::on() ) {
			return;
		}

		echo '<link rel="alternate" type="text/markdown" title="llms.txt" href="' . esc_url( home_url( '/llms.txt' ) ) . '">' . "\n";
		echo '<link rel="alternate" type="application/json" title="MCP server card" href="' . esc_url( home_url( '/.well-known/mcp/server-card.json' ) ) . '">' . "\n";
	}

	/** Which of the files the request asks for, under the site's own path (a store in a subfolder). */
	private static function requested(): ?string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never printed.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( 0 !== strpos( $path, $home ) ) {
			return null;
		}

		$file = substr( $path, strlen( $home ) );

		return isset( self::FILES[ $file ] ) ? $file : null;
	}

	private static function on(): bool {
		return 'live' === Settings::mode() && null !== SiteKeys::site() && (bool) apply_filters( 'let_agents_agent_discovery', true );
	}

	/** The file from Let Agents, kept for six hours; the last one kept when Let Agents does not answer. */
	private static function fetch( string $file ): ?string {
		$key  = 'let_agents_discovery_' . md5( $file );
		$kept = get_transient( $key );

		if ( is_string( $kept ) && '' !== $kept ) {
			return $kept;
		}

		$response = wp_remote_get(
			Settings::api_url() . '/' . sprintf( self::FILES[ $file ][0], rawurlencode( (string) SiteKeys::site() ) ),
			array( 'timeout' => 5 )
		);
		$body     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : (string) wp_remote_retrieve_body( $response );

		if ( null === $body || '' === $body ) {
			$last = get_option( $key . '_last' );

			return is_string( $last ) && '' !== $last ? $last : null;
		}

		set_transient( $key, $body, self::CACHE_SECONDS );
		update_option( $key . '_last', $body, false );

		return $body;
	}
}
