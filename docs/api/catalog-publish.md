## Обновление информации о товарах и торговых предложениях (1С → сайт)

Назначение: публикация событий обновления каталога из 1С. Сообщение помещается в обменник и далее обрабатывается сайтом.

### Эндпоинт

- **Метод**: `POST`
- **Путь**: `/api/exchanges/%2F/1C-Catalog/publish`
- **Тело**: JSON (`application/json; charset=utf-8`)

### Формат запроса

Тело запроса — JSON-объект, содержащий массив `products`.

Пример запроса

```json
{
  "products": [
    {
      "product": {
        "external_code": "PRODUCT001123",
        "name": "Тест товар 1С 1",
        "properties": {
          "URL_DETAIL_IMG": "/RSL_БП-00237141_1.jpg",
          "CATEGORY": "Смартфоны",
          "BREND": "Apple",
          "ART": "123123"
        },
        "quantity": 100,
        "store_quantities": [
          {"mgkl100321": 564}
        ],
        "price": 432,
        "discount_price": 123
      }
    },
    {
      "product": {
        "external_code": "PRODUCT002123",
        "name": "Второй Тест товар 1С 1",
        "properties": {
          "CATEGORY": "Смартфоны",
          "BREND": "Apple",
          "ART": "123123"
        }
      },
      "offers": [
        {
          "external_code": "OFFER001_2123",
          "name": "Второй Тест товар 1С 1",
          "properties": {
            "CATEGORY": "Смартфоны",
            "BREND": "Apple",
            "ART": "123123"
          },
          "quantity": 100,
          "store_quantities": [
            {"mgkl100321": 564}
          ],
          "price": 432,
          "discount_price": 123
        }
      ]
    }
  ]
}
```

### Пояснения к полям

- `products` (array): список продуктов. Каждый элемент содержит информацию о продукте и, при наличии, список его торговых предложений.

- `product` (object): основная информация о конкретном продукте.
  - `external_code` (string): внешний код продукта (GUID/ID из 1С или иная уникальная ссылка).
  - `name` (string, optional): наименование продукта.
  - `properties` (object, optional): произвольные атрибуты продукта в формате ключ/значение.
  - `quantity` (integer, optional): суммарное количество по всем складам.
  - `store_quantities` (array, optional): список объектов вида `{ "<warehouseId>": <qty> }`.
    - Каждый объект содержит единственную пару ключ/значение: идентификатор склада → количество на этом складе.
  - `price` (number, optional): цена без скидки.
  - `discount_price` (number, optional): цена со скидкой.

- `offers` (array, optional): список предложений (SKU) для продукта.
  - Каждый элемент — объект с полями предложения:
    - `external_code` (string): внешний код предложения.
    - `name` (string, optional): наименование предложения.
    - `properties` (object, optional): свойства предложения.
    - `quantity` (integer, optional): суммарное количество предложения на складах.
    - `store_quantities` (array, optional): как у продукта — массив объектов `{ "<warehouseId>": <qty> }`.
    - `price` (number, optional): цена предложения без скидки.
    - `discount_price` (number, optional): цена предложения со скидкой.

### Замечания

- Идентификаторы складов (например, `mgkl100321` или GUID) передаются как ключи в элементах массива `store_quantities`.
- Если для продукта указаны `offers`, агрегированные поля на уровне `product` (цены/остатки) могут использоваться как общие значения по умолчанию или метаданные — конкретная логика зависит от потребителя.


