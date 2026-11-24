import { execFileSync } from 'node:child_process';

export const SESSION_ID = 'e2esession';

const sql = (query) => execFileSync('php', ['bin/console', 'dbal:run-sql', query], { stdio: 'pipe' });

// Strava's login can't run here, so store a session that is already connected as the allowed athlete.
export default function setup() {
    const athlete = process.env.STRAVA_ATHLETE_ID;
    const data = `_sf2_attributes|a:1:{s:17:"strava_athlete_id";s:${athlete.length}:"${athlete}";}`;

    sql(`DELETE FROM strava_weekly_plan WHERE athlete_id = ${athlete}`);
    sql(`DELETE FROM sessions WHERE sess_id = '${SESSION_ID}'`);
    sql(`INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time)
        VALUES ('${SESSION_ID}', '${data}', EXTRACT(EPOCH FROM NOW())::int + 86400, EXTRACT(EPOCH FROM NOW())::int)`);
}
