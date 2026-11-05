import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';

// Запускать из Node-проекта с установленным ajv (он уже есть во фронтенде).
const require = createRequire(resolve(process.cwd(), 'package.json'));
const Ajv = require('ajv/dist/2020').default;
const [specPath, reportPath] = process.argv.slice(2);
if (!specPath) throw new Error('Usage: node validate_contract.mjs <openapi.json> [integration-report.json]');
const spec = JSON.parse(readFileSync(specPath, 'utf8'));
if (spec.openapi !== '3.1.0' || spec.info.version !== '1.0.0') throw new Error('Unexpected contract version');
const ajv = new Ajv({ strict: false, allErrors: true });
ajv.addSchema(spec, 'certificate-contract');
let checks = 0;
function validate(schema, value, context) {
  const validator = ajv.compile({ ...schema, components: spec.components });
  if (!validator(value)) throw new Error(`${context}: ${JSON.stringify(validator.errors)}`);
  checks++;
}
for (const name of Object.keys(spec.components.schemas)) {
  ajv.compile({ $ref: `certificate-contract#/components/schemas/${name}` });
  checks++;
}
const path = spec.paths['/api/1c/certificates/index.php'];
for (const [method, operation] of Object.entries(path)) {
  const request = operation.requestBody?.content?.['application/json'];
  if (request?.example) validate(request.schema, request.example, `${method} request example`);
  for (const [status, response] of Object.entries(operation.responses)) {
    const media = response.content?.['application/json'];
    if (media?.example) validate(media.schema, media.example, `${method} ${status} example`);
  }
}
if (reportPath) {
  const report = JSON.parse(readFileSync(reportPath, 'utf8'));
  if (report.contractVersion !== spec.info.version) throw new Error('Report/contract version mismatch');
  for (const exchange of report.exchanges) {
    const method = exchange.method.toLowerCase();
    const status = String(exchange.response.httpStatus);
    const operation = path[method];
    const response = operation?.responses[status];
    if (operation && !response) throw new Error(`Undocumented response: ${method} ${status}`);
    const schema = response?.content?.['application/json']?.schema || { $ref: '#/components/schemas/Error' };
    validate(schema, exchange.response.body, `${method} ${status} actual response`);
    if (response && status !== '200' && response.content?.['application/json']?.example?.code !== exchange.response.body.code) {
      throw new Error(`Unexpected error code for ${method} ${status}`);
    }
    if (method === 'post' && status === '200') validate(operation.requestBody.content['application/json'].schema, exchange.request, 'accepted callback');
  }
}
console.log(`${checks} OpenAPI/schema checks passed`);
