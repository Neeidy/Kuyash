# Rule: No Overbuild

- Kuyash is NOT: n8n, an enterprise social media suite, a CRM, a generic automation platform, a general-purpose video generator, a spam/slop mass-production engine.
- Do not add features because they are interesting. Every feature must serve the loop: research → create → approve → publish → operate.
- V1 scope: stock-mode production + distribution + compliance + operations. AI avatars, additional AI-video providers, Stripe billing, multi-tenant UI, customer onboarding = V2/SaaS-ification parking lot.
- Prefer simple, working, reviewable increments over clever abstractions.
- The node graph stays simple — no general-purpose graph engine.
- One SQLite job queue. No extra queue systems, no microservices, no agent sprawl.
