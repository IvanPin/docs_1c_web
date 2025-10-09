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
          "CATEGORY": "Смартфоны",
          "BREND": "Apple",
          "ART": "123123",
          "url_detail_img": [
            "\\\\srv-1cbuh-nn.kokonn.local\\pic_unf\\20221029\\AR3344BKZ,WFM\\/B.jpg"
          ]
        },
        "quantity": 100,
        "store_quantities": [
          {
            "store_id": "d0744917-1777-11ea-a69d-00155d006111",
            "quantity": 564
          },
          {
            "store_id": "d0744917-1777-11ea-a69d-00155d006112",
            "quantity": 210
          }
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
            "ART": "123123",
            "url_detail_img": [
              "\\\\srv-1cbuh-nn.kokonn.local\\pic_unf\\20221029\\AR3344BKZ,WFM\\/B.jpg"
            ]
          },
          "quantity": 100,
          "store_quantities": [
            {
              "store_id": "d0744917-1777-11ea-a69d-00155d006111",
              "quantity": 564
            },
            {
              "store_id": "d0744917-1777-11ea-a69d-00155d006112",
              "quantity": 210
            }
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
  - `properties` (object, optional): произвольные атрибуты продукта в формате ключ/значение. Поддерживается, в частности, ключ `url_detail_img` — массив путей или URL к фотографиям товара (например `"\\\\srv-1cbuh-nn.kokonn.local\\pic_unf\\20221029\\AR3344BKZ,WFM/B.jpg"`).
  - `quantity` (integer, optional): суммарное количество по всем складам.
  - `store_quantities` (array, optional): список объектов, описывающих остатки по складам.
    - `store_id` (string): идентификатор склада (GUID, код и т.п.).
    - `quantity` (number): остаток на соответствующем складе.
  - `price` (number, optional): цена без скидки.
  - `discount_price` (number, optional): цена со скидкой.

- `offers` (array, optional): список предложений (SKU) для продукта.
  - Каждый элемент — объект с полями предложения:
    - `external_code` (string): внешний код предложения.
    - `name` (string, optional): наименование предложения.
    - `properties` (object, optional): свойства предложения. Аналогично продукту, может содержать ключ `url_detail_img` со списком путей/URL к фотографиям. Старайтесь не дублировать характеристики, которые уже присутствуют у продукта; в предложении имеет смысл передавать только уникальные атрибуты, специфичные для данного SKU (например, размер кольца).
    - `quantity` (integer, optional): суммарное количество предложения на складах.
    - `store_quantities` (array, optional): аналогично продукту — массив объектов с полями `store_id` и `quantity`.
    - `price` (number, optional): цена предложения без скидки.
    - `discount_price` (number, optional): цена предложения со скидкой.

### Замечания

- Идентификаторы складов (например, `mgkl100321` или GUID) передаются в поле `store_id` элементов массива `store_quantities`.
- Если для продукта указаны `offers`, все значения по ценам, суммарным остаткам и остаткам по складам должны передаваться в рамках каждого конкретного предложения. Поля `price`, `discount_price`, `quantity` и `store_quantities` на уровне `product` в этом случае могут служить только общими значениями по умолчанию или метаданными (по согласованию с потребителем).
- Если у продукта нет `offers`, то данные по `price`, `discount_price`, `quantity` и `store_quantities` заполняются на уровне `product`.
