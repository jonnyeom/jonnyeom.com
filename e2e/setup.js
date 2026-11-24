import { execFileSync } from 'node:child_process';

export default function setup() {
    execFileSync('php', ['tests/E2E/setup.php'], { stdio: 'inherit' });
}
