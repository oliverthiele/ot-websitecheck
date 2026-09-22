#
# Table structure for table 'tx_otwebsitecheck_domain_model_check'
#
CREATE TABLE tx_otwebsitecheck_domain_model_check
(

	url             varchar(1024)       DEFAULT ''  NOT NULL,
	path            varchar(1024)       DEFAULT ''  NOT NULL,
	environment     varchar(255)        DEFAULT ''  NOT NULL,
	source          varchar(1024)       DEFAULT ''  NOT NULL,
	page_uid        int(11)  unsigned   DEFAULT '0' NOT NULL,
	http_status     int(11)             DEFAULT '0' NOT NULL,
	error_marker    varchar(255)        DEFAULT ''  NOT NULL,
	checked_at      int(11)  unsigned   DEFAULT '0' NOT NULL,
	run_started_at  int(11)  unsigned   DEFAULT '0' NOT NULL,
	reviewed        tinyint(4) unsigned DEFAULT '0' NOT NULL,
	note            text                            NOT NULL,

	KEY url_environment (url(255), environment),
	KEY environment_run (environment, run_started_at),
	KEY page_uid (page_uid),
	KEY path (path(255))
);

#
# Table structure for table 'tx_otwebsitecheck_domain_model_observation'
#
# Columns are generated from TCA; only the indexes need to be declared here.
#
CREATE TABLE tx_otwebsitecheck_domain_model_observation
(
	KEY run_environment_path (run_label, environment, requested_path(255)),
	KEY run_identity (run_label, page_uid, record_uid)
);

#
# Table structure for table 'tx_otwebsitecheck_domain_model_sitemapsnapshot'
#
# Columns are generated from TCA; only the indexes need to be declared here.
# uuid is declared as a nullable string: records created before the column
# existed have none until they are exported, which TCA type "uuid" (a
# NOT NULL GUID without default) does not allow on every database. NULL keeps
# the unique index valid for all of them.
#
CREATE TABLE tx_otwebsitecheck_domain_model_sitemapsnapshot
(
	uuid varchar(36) DEFAULT NULL,

	KEY label (label),
	UNIQUE KEY uuid (uuid)
);

#
# Table structure for table 'tx_otwebsitecheck_domain_model_sitemapdocument'
#
# Columns are generated from TCA; only the indexes need to be declared here.
#
CREATE TABLE tx_otwebsitecheck_domain_model_sitemapdocument
(
	KEY snapshot_language (snapshot, language)
);

#
# Table structure for table 'tx_otwebsitecheck_domain_model_sitemapurl'
#
# Columns are generated from TCA; only the indexes need to be declared here.
#
CREATE TABLE tx_otwebsitecheck_domain_model_sitemapurl
(
	KEY snapshot_language_group (snapshot, language, sitemap_group),
	KEY document (document)
);

#
# Table structure for table 'tx_otwebsitecheck_domain_model_migrationrun'
#
# Columns are generated from TCA; uuid as for the snapshot table.
#
CREATE TABLE tx_otwebsitecheck_domain_model_migrationrun
(
	uuid varchar(36) DEFAULT NULL,

	KEY run_label (run_label),
	UNIQUE KEY uuid (uuid)
);
