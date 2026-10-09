-- Social media area (Instagram, Facebook page, YouTube Shorts). Kept in own tables: no social value
-- ever lands in metric_daily, metric_period or metric_totals, so it can never mix with podcast reach.

-- One row per post, reel, story or short.
CREATE TABLE social_posts (
  id INTEGER PRIMARY KEY,
  platform TEXT NOT NULL CHECK (platform IN ('instagram', 'facebook', 'youtube_shorts')),
  external_id TEXT NOT NULL,
  format TEXT NOT NULL CHECK (format IN ('reel', 'carousel', 'image', 'video', 'story', 'short', 'text')),
  caption TEXT,
  permalink TEXT,
  thumbnail_url TEXT,
  published_at TEXT NOT NULL,
  -- Series ("Fehler der Woche" …): detected from a hashtag in the caption, or set by hand.
  series TEXT,
  series_manual INTEGER NOT NULL DEFAULT 0,
  -- Stories disappear after 24 hours; their numbers must be stored while they are live.
  expires_at TEXT,
  updated_at TEXT NOT NULL,
  UNIQUE (platform, external_id)
);
CREATE INDEX social_posts_published ON social_posts (platform, published_at);

-- Lifetime values of a post as captured on a day (the newest row per post is the current value).
CREATE TABLE social_post_metrics_daily (
  post_id INTEGER NOT NULL REFERENCES social_posts (id),
  date TEXT NOT NULL,
  metric TEXT NOT NULL,
  value REAL NOT NULL,
  captured_at TEXT NOT NULL,
  PRIMARY KEY (post_id, date, metric)
);

-- Account values per day (reach, views, interactions …) and daily follower snapshots.
CREATE TABLE social_account_metrics_daily (
  platform TEXT NOT NULL,
  metric TEXT NOT NULL,
  date TEXT NOT NULL,
  value REAL NOT NULL,
  source TEXT NOT NULL,
  imported_at TEXT NOT NULL,
  PRIMARY KEY (platform, metric, date)
);
CREATE INDEX social_account_range ON social_account_metrics_daily (platform, metric, date);

-- Visits on monteur-podcast.de with utm_source=instagram|facebook|youtube_shorts|fb_gruppe (from the access logs).
CREATE TABLE social_visits_daily (
  date TEXT NOT NULL,
  source TEXT NOT NULL,
  visits INTEGER NOT NULL,
  PRIMARY KEY (date, source)
);

-- Facebook groups have no API since 2024: Markus notes his group answers per week by hand.
CREATE TABLE social_group_log (
  week TEXT PRIMARY KEY,
  answers INTEGER NOT NULL,
  note TEXT,
  updated_at TEXT NOT NULL
);
