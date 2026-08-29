import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'app');
const port = Number(process.env.MEMI_PREVIEW_PORT || 4178);
const now = Date.now();
const state = {
  authenticated: true,
  csrf: 'preview-only',
  user: { id: 42, name: 'Camille Tremblay', email: 'camille@example.com', administrator: true },
  metrics: { credits: 5, points: 325, upcomingBookings: 2 },
  preferences: { courses: true, promotions: true, other: false },
  subscribed: false,
  pushAvailable: false,
  vapidPublicKey: '',
  courses: [
    { id: 1, title: 'Sol stabilité', startsAt: new Date(now + 86400000).toISOString(), instructor: 'Sophie', room: 'Salle Sol', remaining: 6, url: '/app/' },
    { id: 2, title: 'Reformer essentiel', startsAt: new Date(now + 2 * 86400000).toISOString(), instructor: 'Marie', room: 'Salle Reformer', remaining: 2, url: '/app/' },
    { id: 3, title: 'Mobilité douce', startsAt: new Date(now + 4 * 86400000).toISOString(), instructor: 'Élodie', room: 'Salle Sol', remaining: 9, url: '/app/' },
  ],
  promotions: [{ id: 1, code: 'MEMI20', title: 'Découverte Memi', description: 'Profitez de 20 % sur votre premier forfait.', discountPercent: 20, url: '/app/' }],
  notifications: [
    { id: 1, category: 'courses', title: 'Nouveau cours : Barre & mobilité', body: 'Une nouvelle séance est maintenant offerte samedi matin.', url: '/app/', availableAt: new Date(now - 3600000).toISOString() },
    { id: 2, category: 'promotions', title: 'Votre semaine bien-être', body: 'Un avantage spécial est offert aux membres Memi.', url: '/app/', availableAt: new Date(now - 86400000).toISOString() },
  ],
  announcements: [],
  admin: { activeSubscriptions: 28, optedInUsers: 22, queuedDeliveries: 0, lastCronAt: new Date(now - 180000).toISOString(), pushLibraryReady: true },
  generatedAt: new Date(now).toISOString(),
};

const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png' };
const json = (res, data) => { res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }); res.end(JSON.stringify({ ok: true, data })); };

http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host}`);
  if (url.pathname.startsWith('/app/api/')) {
    if (url.pathname.endsWith('/state.php')) return json(res, state);
    return json(res, { saved: true });
  }
  let relative = url.pathname.replace(/^\/app\/?/, '');
  if (!relative || relative.endsWith('/')) relative += 'index.html';
  const file = path.resolve(root, relative);
  if (!file.startsWith(root + path.sep) && file !== root) { res.writeHead(403); return res.end('Forbidden'); }
  try {
    const body = await fs.readFile(file);
    res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    res.end(body);
  } catch {
    res.writeHead(404); res.end('Not found');
  }
}).listen(port, '127.0.0.1', () => console.log(`Memi PWA preview: http://127.0.0.1:${port}/app/`));
