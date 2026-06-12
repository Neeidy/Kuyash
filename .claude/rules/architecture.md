# Rule: Architecture

- Pure PHP 8.3 modular architecture: clear separation of concerns, service classes, simple routing, central config loading, central error handling. No full frameworks.
- The production pipeline is a chain of durable SQLite jobs:
  `trend_fetch → idea_generation → script_draft (approval) → tts → asset_fetch → assembly → compliance_check → render_review (approval mode) → publish`
- Queue and worker logic are separated from web request handling.
- Every external capability sits behind a provider-agnostic adapter interface (TtsProvider, VideoGenProvider, StockProvider, TrendProvider, MusicProvider, PublishProvider, ImageGenProvider). Core code never references vendor names; adapters translate vendor responses into one internal shape (file, duration, cost, metadata). Swapping a provider = one adapter class + one config line.
- Mock adapters implement the same interfaces — mock-first is the default state of every integration.
- Multi-tenant schema from day one: workspace_id on all tenant data; single-user UI in V1.
- No uncontrolled dependencies. Composer packages require explicit approval.
