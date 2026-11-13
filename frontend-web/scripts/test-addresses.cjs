const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const ts = require('typescript');
const filename = path.resolve(__dirname, '../src/addressOrder.ts');
const compiled = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const loaded = { exports: {} };
vm.runInThisContext(`(function(exports) { ${compiled.outputText}\n})`, { filename })(loaded.exports);
const { compareAddresses, streetOf } = loaded.exports;

test('Les rues passent avant les numéros, les suffixes équivalents gardent leur ordre', () => {
  const lines = ['2 rue Zola', '171 rue Alpha', '3t rue Alpha', '2 rue Alpha', '3 ter RUE Alpha', '10 rue Zola', '3 rue Alpha'];
  const addresses = lines.map((ligne1, id) => ({ id, ligne1 }));
  assert.deepEqual([...addresses].sort(compareAddresses).map(a => a.id), [3, 2, 4, 6, 1, 0, 5]);
  assert.deepEqual([...addresses].sort((a, b) => -compareAddresses(a, b)).map(a => a.id), [5, 0, 1, 2, 4, 6, 3]);
  assert.deepEqual(addresses.map(a => a.ligne1), lines);
});

test('Les suffixes séparés ou accolés donnent la même rue ; les numéros sont numériques', () => {
  for (const prefix of ['3t', '3 ter', '003 bis', '3', '3B', '3 B']) {
    assert.equal(streetOf({ ligne1: ` ${prefix}  rue Exemple ` }), 'rue Exemple');
  }
  const addresses = ['171 rue Exemple', '10 rue Exemple', 'rue Exemple', '2 rue Exemple'].map(ligne1 => ({ ligne1 }));
  assert.deepEqual(addresses.sort(compareAddresses).map(a => a.ligne1), ['2 rue Exemple', '10 rue Exemple', '171 rue Exemple', 'rue Exemple']);
});
