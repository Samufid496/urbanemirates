<?php
/**
 * Draft Privacy Policy and Terms of Use pages, created once and then editable in WordPress.
 * They describe what this plugin actually does. Have a lawyer review them before launch.
 *
 * Shortcode: [kj_contact_email] prints the contact address from Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Legal {

	public static function init() {
		add_shortcode( 'kj_contact_email', array( __CLASS__, 'contact_email_shortcode' ) );
	}

	public static function contact_email() {
		$email = (string) kj_setting( 'contact_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	public static function contact_email_shortcode() {
		$email = self::contact_email();
		return '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	}

	public static function terms_url() {
		$id = (int) get_option( 'kj_page_terms' );
		return $id ? get_permalink( $id ) : home_url( '/' );
	}

	public static function privacy_url() {
		$url = get_privacy_policy_url();
		return $url ? $url : home_url( '/' );
	}

	public static function create_pages() {
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		$ours    = (int) get_option( 'kj_page_privacy' );
		if ( ( ! $ours || ! get_post( $ours ) ) && $privacy && 'draft' === get_post_status( $privacy ) ) {
			// Fresh sites have WordPress's sample privacy page as an unpublished draft: fill it in.
			wp_update_post(
				array(
					'ID'           => $privacy,
					'post_status'  => 'publish',
					'post_title'   => 'Privacy Policy',
					'post_content' => self::privacy_text(),
				)
			);
			update_option( 'kj_page_privacy', $privacy );
			$ours = $privacy;
		}
		if ( ! $ours || ! get_post( $ours ) ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Privacy Policy',
					'post_name'    => 'privacy-policy-urban-emirates',
					'post_content' => self::privacy_text(),
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'kj_page_privacy', $id );
				// Replace WordPress's sample privacy page (usually an unpublished draft).
				if ( ! $privacy || 'publish' !== get_post_status( $privacy ) ) {
					update_option( 'wp_page_for_privacy_policy', $id );
				}
			}
		}
		$terms = (int) get_option( 'kj_page_terms' );
		if ( ! $terms || ! get_post( $terms ) ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Terms of Use',
					'post_name'    => 'terms',
					'post_content' => self::terms_text(),
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'kj_page_terms', $id );
			}
		}
	}

	private static function privacy_text() {
		return <<<'HTML'
<p><em>Last updated: when this page was published.</em></p>

<p>Urban Emirates (urbanemirates.com) is a free job website for people looking for work in the Gulf and abroad. This policy explains what personal data we collect, why, and the choices you have. It is written to meet India's Digital Personal Data Protection Act, 2023 and the data protection laws of the UAE, Saudi Arabia, Qatar, Bahrain, Oman and Kuwait.</p>

<h2>What we collect</h2>
<ul>
<li><strong>Your email address</strong> — when you sign in, create a CV or sign up for job alerts.</li>
<li><strong>Your CV</strong> — everything you type into the CV builder, which may include your name, phone number, city, nationality, date of birth, visa status, notice period, work history, education, skills, licences and an optional photo. All fields except your name are optional.</li>
<li><strong>Your choices</strong> — saved jobs, job alert preferences, and whether you want job emails.</li>
<li><strong>Technical data</strong> — basic server logs (such as IP address and browser type) used for security and to stop abuse, and the cookies needed to keep you signed in.</li>
</ul>
<p>We never ask for your passport number, Aadhaar or Emirates ID number, or bank details. Please do not type them into your CV.</p>

<h2>Why we use it</h2>
<ul>
<li>To let you sign in, build, save and download your CV.</li>
<li>To email you sign-in codes, and — only if you ask for it — daily jobs that match your CV or your alert choices.</li>
<li>To keep the website secure and stop spam and fraud.</li>
</ul>
<p>We do not sell your data and we do not share your CV with employers. You decide where you send your CV.</p>

<h2>AI help</h2>
<p>When you press an AI button in the CV builder (for example "Write it for me" or "Improve with AI"), the CV text needed for that request is sent to our AI provider, Anthropic (Claude), to create a suggestion. Your email address and phone number are not sent. Suggestions appear only for you, and nothing is added to your CV unless you choose "Use this". We also use AI to check job posts for scam signs before we publish them.</p>

<h2>Who processes data for us</h2>
<ul>
<li>Our web host, which stores the website and its database.</li>
<li>Our email provider, which delivers sign-in codes and job emails.</li>
<li>Anthropic, for AI suggestions as described above.</li>
<li>Cloudflare Turnstile, which checks that forms are filled in by people and not bots.</li>
</ul>
<p>Some of these providers may process data outside your country. We use them only for the purposes above.</p>

<h2>Consent and your choices</h2>
<p>We ask for your agreement when you sign in. Job emails are a separate, optional choice that you can turn off at any time from My account or with the unsubscribe link in every email.</p>

<h2>Your rights</h2>
<ul>
<li><strong>See and download your data</strong> — use "Download my data" in My account.</li>
<li><strong>Correct it</strong> — edit your CV and preferences at any time.</li>
<li><strong>Delete it</strong> — use "Delete my account". This removes your account, CVs, saved jobs and preferences permanently.</li>
<li><strong>Withdraw consent</strong> — turn off job emails, or delete your account.</li>
<li><strong>Complain</strong> — contact us first (below). You may also contact the data protection authority in your country.</li>
</ul>

<h2>How long we keep data</h2>
<p>We keep your account and CVs until you delete them. Sign-in codes expire after 10 minutes. Email-only job alert sign-ups are kept until you unsubscribe.</p>

<h2>Security</h2>
<p>Sign-in uses one-time codes instead of passwords, the website uses HTTPS, and access to the database is restricted. No method is perfect, so please use a CV that contains only what an employer needs.</p>

<h2>Children</h2>
<p>Urban Emirates is for people aged 18 and over.</p>

<h2>Advertising</h2>
<p>We may show a small number of ads in the future. If we do, we will update this policy and any cookie choices before ads appear.</p>

<h2>Contact and grievances</h2>
<p>For any question, request or complaint about your data, email [kj_contact_email]. We aim to reply within 7 days.</p>
HTML;
	}

	private static function terms_text() {
		return <<<'HTML'
<p><em>Last updated: when this page was published.</em></p>

<p>By using Urban Emirates (urbanemirates.com) you agree to these terms.</p>

<h2>What we are</h2>
<p>Urban Emirates is a free website that lists jobs found on other websites and helps you build a CV. <strong>We are not an employer or a recruitment agency.</strong> When you apply for a job, you apply on the original website, and your application is between you and that employer or agency.</p>

<h2>We never charge job seekers</h2>
<p>Using Urban Emirates is free. We will never ask you for money for a job, a visa or registration. If anyone asks you to pay in our name, it is a scam — please tell us.</p>

<h2>Job listings</h2>
<p>We check every job for common scam signs and a person reviews it before it is published, but we cannot guarantee that a job is real, still open, or accurately described. Always check the employer yourself, never pay fees, and read your contract before you travel. Report any suspicious job to [kj_contact_email] and we will review it.</p>

<h2>Your account and CV</h2>
<ul>
<li>Use your own email address and keep access to it private.</li>
<li>Only put true information in your CV. AI suggestions are based on what you write, but you are responsible for checking that everything in your CV is accurate before you use it.</li>
<li>Do not use the website to send spam, collect other people's data, or post anything unlawful.</li>
</ul>

<h2>Content and links</h2>
<p>Job descriptions on this site are short summaries with links to the original posts, which belong to their owners. The website design and our own text belong to Urban Emirates. Your CV belongs to you.</p>

<h2>Limits of responsibility</h2>
<p>The website is provided "as is". As far as the law allows, Urban Emirates is not responsible for decisions you make based on a job listing, for the actions of employers, agencies or other websites, or for losses caused by the website being unavailable.</p>

<h2>Changes</h2>
<p>We may update these terms. If we make important changes, we will show a notice on the website.</p>

<h2>Law</h2>
<p>These terms are governed by the laws of India, without affecting any rights you have under the laws of the country where you live.</p>

<h2>Contact</h2>
<p>Email [kj_contact_email].</p>
HTML;
	}
}
