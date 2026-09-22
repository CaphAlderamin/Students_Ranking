const fs = require('fs');
const path = require('path');
const { Parser } = require('@dbml/core');

const ROOT = path.resolve(__dirname, '..', '..');
const SCHEMA = path.join(ROOT, 'database', 'schema.dbml');
const OUT = path.join(ROOT, 'docs', 'schema.mmd');

try {
  const dbml = fs.readFileSync(SCHEMA, 'utf8');
  const db = Parser.parse(dbml, 'dbml');
  const model = db.export();

  const fkFields = new Set();
  const relLines = [];

  for (const schema of model.schemas) {
    for (const ref of schema.refs) {
      const parent = ref.endpoints[0];
      const child = ref.endpoints[1];
      for (const endpoint of ref.endpoints) {
        if (endpoint.relation === '*') {
          for (const fieldName of endpoint.fieldNames) {
            fkFields.add(endpoint.tableName + '.' + fieldName);
          }
        }
      }
      if (parent.relation === '1' && child.relation === '*') {
        relLines.push('  ' + parent.tableName + ' ||--o{ ' + child.tableName + ' : ""');
      }
    }
  }

  const entityLines = [];
  for (const schema of model.schemas) {
    for (const table of schema.tables) {
      entityLines.push('  ' + table.name + ' {');
      for (const field of table.fields) {
        const keys = [];
        if (field.pk) keys.push('PK');
        if (field.unique) keys.push('UK');
        if (fkFields.has(table.name + '.' + field.name)) keys.push('FK');
        const type = String(field.type.type_name);
        entityLines.push('    ' + type + ' ' + field.name + (keys.length ? ' ' + keys.join(' ') : ''));
      }
      entityLines.push('  }');
    }
  }

  const mermaid = 'erDiagram\n' + entityLines.join('\n') + '\n' + relLines.join('\n');
  fs.writeFileSync(OUT, '```mermaid\n' + mermaid + '\n```\n');
  console.log('OK: ' + path.relative(ROOT, OUT) + ' generated');
} catch (err) {
  console.error('DBML -> mermaid error: ' + err.message);
  process.exit(1);
}