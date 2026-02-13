import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import path from 'node:path';

const PORT = Number(process.env.PORT ?? 3000);
const STORAGE_ROOT = process.env.STORAGE_ROOT
  ? path.resolve(process.env.STORAGE_ROOT)
  : path.resolve(process.cwd(), '..', 'storage');

const allowedDomainsRaw = process.env.ALLOWED_EMAIL_DOMAINS ?? '';
const allowedDomains = new Set(
  allowedDomainsRaw
    .split(/[;,\s]+/)
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean)
);

const app = express();
app.use(helmet());
app.use(cors({ origin: true }));
app.use(express.json({ limit: '256kb' }));

app.get('/api/health', (_req, res) => {
  res.json({ ok: true, storageRoot: STORAGE_ROOT });
});

app.listen(PORT, () => {
  // eslint-disable-next-line no-console
  console.log(`[backend] listening on http://localhost:${PORT}`);
  // eslint-disable-next-line no-console
  console.log(`[backend] storage root: ${STORAGE_ROOT}`);
});
