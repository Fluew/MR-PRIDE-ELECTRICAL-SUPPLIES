import { createServer } from 'vite';
const server = await createServer({ server: { port: 5174, origin: 'http://127.0.0.1:5174', hmr: { host: '127.0.0.1' } } });
await server.listen();
server.printUrls();
