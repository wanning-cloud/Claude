-- Podcast-Cockpit schema. Dates are Europe/Berlin calendar dates (YYYY-MM-DD),
-- timestamps are ISO 8601 with offset.

CREATE TABLE episodes (
  id INTEGER PRIMARY KEY,
  guid TEXT NOT NULL UNIQUE,
  number INTEGER,
  title TEXT NOT NULL,
  published_at TEXT NOT NULL,
  mp3_url TEXT NOT NULL,
  mp3_path TEXT NOT NULL,
  bytes INTEGER,
  duration_s INTEGER,
  youtube_video_id TEXT,
  youtube_manual INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL
);
CREATE INDEX episodes_path ON episodes (mp3_path);

CREATE TABLE youtube_videos (
  video_id TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  published_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

-- Foreign titles or ids of an episode on a platform (Spotify, Apple, Amazon, Deezer rows).
CREATE TABLE episode_aliases (
  id INTEGER PRIMARY KEY,
  platform TEXT NOT NULL,
  foreign_key TEXT NOT NULL,
  foreign_title TEXT NOT NULL,
  episode_id INTEGER REFERENCES episodes (id),
  status TEXT NOT NULL CHECK (status IN ('confirmed', 'pending', 'ignored')),
  created_at TEXT NOT NULL,
  UNIQUE (platform, foreign_key)
);

-- episode_id 0 = whole show (channel or show level value).
CREATE TABLE metric_daily (
  episode_id INTEGER NOT NULL,
  platform TEXT NOT NULL,
  metric TEXT NOT NULL,
  date TEXT NOT NULL,
  value REAL NOT NULL,
  source TEXT NOT NULL,
  imported_at TEXT NOT NULL,
  PRIMARY KEY (episode_id, platform, metric, date)
);
CREATE INDEX metric_daily_range ON metric_daily (platform, metric, date);

-- Values that only exist for a whole period (routine readings from Apple, Amazon, Spotify without date column).
CREATE TABLE metric_period (
  episode_id INTEGER NOT NULL,
  platform TEXT NOT NULL,
  metric TEXT NOT NULL,
  period_from TEXT NOT NULL,
  period_to TEXT NOT NULL,
  value REAL NOT NULL,
  source TEXT NOT NULL,
  imported_at TEXT NOT NULL,
  PRIMARY KEY (episode_id, platform, metric, period_from, period_to)
);

-- Lifetime counters with capture time (YouTube videos.list statistics, show followers).
CREATE TABLE metric_totals (
  episode_id INTEGER NOT NULL,
  platform TEXT NOT NULL,
  metric TEXT NOT NULL,
  captured_at TEXT NOT NULL,
  value REAL NOT NULL,
  ref TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (episode_id, platform, metric, captured_at, ref)
);

-- IAB counting state. key_hash = HMAC(ip|user agent) with a daily salt, never the IP itself.
CREATE TABLE download_windows (
  id INTEGER PRIMARY KEY,
  key_hash TEXT NOT NULL,
  episode_id INTEGER NOT NULL,
  window_start TEXT NOT NULL,
  bytes INTEGER NOT NULL,
  counted INTEGER NOT NULL DEFAULT 0,
  app TEXT NOT NULL
);
CREATE INDEX download_windows_lookup ON download_windows (key_hash, episode_id, window_start);

CREATE TABLE download_daily (
  episode_id INTEGER NOT NULL,
  date TEXT NOT NULL,
  app TEXT NOT NULL,
  downloads INTEGER NOT NULL,
  PRIMARY KEY (episode_id, date, app)
);

CREATE TABLE daily_salts (
  day TEXT PRIMARY KEY,
  salt TEXT NOT NULL
);

CREATE TABLE comments (
  id INTEGER PRIMARY KEY,
  platform TEXT NOT NULL,
  external_id TEXT NOT NULL,
  parent_id INTEGER REFERENCES comments (id),
  episode_id INTEGER REFERENCES episodes (id),
  alias_id INTEGER REFERENCES episode_aliases (id),
  video_id TEXT,
  author_name TEXT NOT NULL,
  author_url TEXT,
  author_channel_id TEXT,
  title TEXT,
  rating INTEGER,
  text TEXT NOT NULL,
  posted_at TEXT NOT NULL,
  updated_at TEXT,
  status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'answered', 'done', 'later')),
  tags TEXT,
  note TEXT,
  from_host INTEGER NOT NULL DEFAULT 0,
  answered_at TEXT,
  raw_json TEXT,
  UNIQUE (platform, external_id)
);
CREATE INDEX comments_inbox ON comments (parent_id, status, posted_at);

CREATE TABLE reply_queue (
  id INTEGER PRIMARY KEY,
  comment_id INTEGER NOT NULL REFERENCES comments (id),
  text TEXT NOT NULL,
  channel TEXT NOT NULL CHECK (channel IN ('api', 'routine')),
  state TEXT NOT NULL CHECK (state IN ('queued', 'sending', 'sent', 'failed')),
  approved_at TEXT NOT NULL,
  sent_at TEXT,
  external_id TEXT,
  error TEXT
);

CREATE TABLE imports (
  id INTEGER PRIMARY KEY,
  type TEXT NOT NULL,
  source TEXT NOT NULL,
  kind TEXT NOT NULL,
  checksum TEXT NOT NULL UNIQUE,
  rows INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  original TEXT NOT NULL
);

CREATE TABLE sync_runs (
  id INTEGER PRIMARY KEY,
  source TEXT NOT NULL,
  trigger TEXT NOT NULL,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  ok INTEGER,
  items INTEGER NOT NULL DEFAULT 0,
  message TEXT
);
CREATE INDEX sync_runs_source ON sync_runs (source, started_at);

CREATE TABLE routine_runs (
  id INTEGER PRIMARY KEY,
  source TEXT NOT NULL,
  kind TEXT NOT NULL,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  ok INTEGER,
  message TEXT
);

CREATE TABLE sync_locks (
  source TEXT PRIMARY KEY,
  locked_until TEXT NOT NULL
);

CREATE TABLE connections (
  platform TEXT PRIMARY KEY,
  access_token_enc TEXT,
  refresh_token_enc TEXT,
  expires_at TEXT,
  account TEXT,
  scopes TEXT,
  updated_at TEXT NOT NULL
);

CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  target TEXT,
  detail TEXT
);

CREATE TABLE settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
