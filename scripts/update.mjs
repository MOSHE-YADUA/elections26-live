// מושך את תוצאות הספירה מ-GovMap ושומר סיכום ארצי ב-data/live.json
import { readFile, writeFile } from "node:fs/promises";

const SRC = "https://elections.govmap.gov.il/data/votes/latest/municipal_data.json";
const OUT = new URL("../data/live.json", import.meta.url);

const res = await fetch(`${SRC}?v=${Date.now()}`, { headers: { "User-Agent": "elections26-live" } });
if (!res.ok) throw new Error(`GovMap answered ${res.status}`);
const d = await res.json();
if (!d.generated || !d.features) throw new Error("GovMap file has no results");

const sum = { bzb: 0, voters: 0, invalid: 0, valid: 0 };
const votes = Object.fromEntries(d.parties.filter(Boolean).map(k => [k, 0]));
for (const f of Object.values(d.features)) {
  sum.bzb += f["בזב"] || 0;
  sum.voters += f["מצביעים"] || 0;
  sum.invalid += f["פסולים"] || 0;
  sum.valid += f["כשרים"] || 0;
  for (const k in votes) votes[k] += f[k] || 0;
}

const out = { generated: d.generated, source: d.source, kalpi_counted: d.totals?.kalpi_counted ?? null, ...sum, votes };

let prev = null;
try { prev = JSON.parse(await readFile(OUT, "utf8")); } catch {}
if (prev?.generated === out.generated) {
  console.log("no change", out.generated);
} else {
  await writeFile(OUT, JSON.stringify(out) + "\n");
  console.log("updated", out.generated, "valid", out.valid);
}
