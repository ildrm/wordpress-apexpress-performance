<?php
/**
 * Privacy-conscious Real User Monitoring collector.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_RUM {
	public const TABLE_SUFFIX = 'apexpress_rum_daily';

	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 1000 );
		add_action( 'apexpress_daily_maintenance', array( $this, 'cleanup_old' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public function enqueue(): void {
		if ( ! ApexPress_Settings::get( 'rum_enabled' ) || is_admin() || is_user_logged_in() ) {
			return;
		}
		$sample   = (int) ApexPress_Settings::get( 'rum_sample_rate', 10 );
		$endpoint = rest_url( 'apexpress/v1/rum' );
		$script   = <<<'JS'
(function(SAMPLE,ENDPOINT){
  if(Math.random()*100>SAMPLE)return;
  var m={lcp:0,cls:0,inp:0,fcp:0,ttfb:0}, sent=false;
  var clsWindow=0,clsWindowStart=0,clsLast=0,interactions={};
  try{
    var n=performance.getEntriesByType('navigation')[0];if(n)m.ttfb=Math.max(0,n.responseStart);
    var p=performance.getEntriesByName('first-contentful-paint')[0];if(p)m.fcp=p.startTime;
    new PerformanceObserver(function(l){l.getEntries().forEach(function(e){m.lcp=e.startTime;});}).observe({type:'largest-contentful-paint',buffered:true});
    new PerformanceObserver(function(l){l.getEntries().forEach(function(e){
      if(e.hadRecentInput)return;
      if(!clsWindowStart || e.startTime-clsLast>1000 || e.startTime-clsWindowStart>5000){clsWindow=e.value;clsWindowStart=e.startTime;}else{clsWindow+=e.value;}
      clsLast=e.startTime;m.cls=Math.max(m.cls,clsWindow);
    });}).observe({type:'layout-shift',buffered:true});
    if(PerformanceObserver.supportedEntryTypes&&PerformanceObserver.supportedEntryTypes.indexOf('event')>-1){
      new PerformanceObserver(function(l){l.getEntries().forEach(function(e){if(!e.interactionId)return;interactions[e.interactionId]=Math.max(interactions[e.interactionId]||0,e.duration);});}).observe({type:'event',durationThreshold:40,buffered:true});
    }
  }catch(e){}
  function finalizeInp(){var a=Object.keys(interactions).map(function(k){return interactions[k];}).sort(function(a,b){return a-b;});if(a.length)m.inp=a[Math.min(a.length-1,Math.floor((a.length-1)*0.98))];}
  function send(){if(sent)return;sent=true;finalizeInp();var d={path:location.pathname.slice(0,250),device:matchMedia('(max-width: 782px)').matches?'mobile':'desktop',lcp:Math.round(m.lcp),cls:Math.round(m.cls*1000)/1000,inp:Math.round(m.inp),fcp:Math.round(m.fcp),ttfb:Math.round(m.ttfb)};try{navigator.sendBeacon(ENDPOINT,new Blob([JSON.stringify(d)],{type:'application/json'}));}catch(e){}}
  addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')send();});
  addEventListener('pagehide',send,{once:true});
})(__APX_SAMPLE__,__APX_ENDPOINT__);
JS;
		$script = str_replace(
			array( '__APX_SAMPLE__', '__APX_ENDPOINT__' ),
			array( (string) $sample, (string) wp_json_encode( esc_url_raw( $endpoint ) ) ),
			$script
		);
		wp_register_script( 'apexpress-rum', false, array(), APEXPRESS_VERSION, true );
		wp_enqueue_script( 'apexpress-rum' );
		wp_add_inline_script( 'apexpress-rum', $script );
	}

	public static function ingest( array $data ): bool {
		global $wpdb;
		$path   = isset( $data['path'] ) ? sanitize_text_field( (string) $data['path'] ) : '/';
		$bucket = self::classify_path( $path );
		$device = isset( $data['device'] ) && 'mobile' === $data['device'] ? 'mobile' : 'desktop';
		$metric_names = array( 'lcp', 'cls', 'inp', 'fcp', 'ttfb' );
		$day = gmdate( 'Y-m-d' );
		$table = self::table();
		$ok = true;
		foreach ( $metric_names as $metric ) {
			if ( ! isset( $data[ $metric ] ) || ! is_numeric( $data[ $metric ] ) ) {
				continue;
			}
			$value = (float) $data[ $metric ];
			$maximum = 'cls' === $metric ? 10.0 : 120000.0;
			if ( $value < 0 || $value > $maximum ) {
				continue;
			}
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (day, path_hash, path_sample, device, metric, samples, total, minimum, maximum) VALUES (%s,%s,%s,%s,%s,1,%f,%f,%f)
				 ON DUPLICATE KEY UPDATE samples=samples+1,total=total+VALUES(total),minimum=LEAST(minimum,VALUES(minimum)),maximum=GREATEST(maximum,VALUES(maximum)),path_sample=VALUES(path_sample)",
				$day,
				hash( 'sha256', $bucket ),
				$bucket,
				$device,
				$metric,
				$value,
				$value,
				$value
			);
			$ok = false !== $wpdb->query( $sql ) && $ok; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		return $ok;
	}

	private static function classify_path( string $path ): string {
		$path = '/' . ltrim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
		if ( '/' === $path ) {
			return 'front-page';
		}
		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			return $post_type ? 'post:' . sanitize_key( $post_type ) : 'post:unknown';
		}
		if ( str_contains( $path, '/wp-json/' ) ) {
			return 'route:rest';
		}
		return 'route:other';
	}

	public static function summary( int $days = 7 ): array {
		global $wpdb;
		$days  = min( 90, max( 1, $days ) );
		$table = self::table();
		$since = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric, device, SUM(samples) AS samples, ROUND(SUM(total)/NULLIF(SUM(samples),0),3) AS average, MIN(minimum) AS minimum, MAX(maximum) AS maximum FROM {$table} WHERE day >= %s GROUP BY metric, device ORDER BY metric, device",
				$since
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function cleanup_old(): void {
		global $wpdb;
		$retention = (int) ApexPress_Settings::get( 'rum_retention_days', 30 );
		$cutoff    = gmdate( 'Y-m-d', time() - $retention * DAY_IN_SECONDS );
		$table     = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
