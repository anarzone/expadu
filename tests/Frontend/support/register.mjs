// node --import ./tests/Frontend/support/register.mjs: resolve the app's module paths (resolve.mjs).
import { register } from 'node:module';

register('./resolve.mjs', import.meta.url);
