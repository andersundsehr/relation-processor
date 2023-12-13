# EXT:relation_processor

## install

``composer req andersundsehr/relation-processor``

## what does it do

It adds a RelationProcessor so you don't have to manually define a DatabaseQueryProcessor for each relation you want to use.  
It uses the TCA configuration to determine the correct query to use.  
It uses the `PageRepository->versionOL()` and `PageRepository->getLanguageOverlay()` functions so it hase correct versioning and language overlay support.  



### Example

````typo3_typoscript
10 = AUS\RelationProcessor\DataProcessing\RelationProcessor
10 {
    # this field is of the current table and will be used to determine the relation
    # eg. if you have EXT:news and this processor is used on a tt_content you can get all related news like this:
    field = tx_news_related_news
}
````

### Advanced Example

````typo3_typoscript
page = PAGE
page.10 = FLUIDTEMPLATE
page.10 {
    dataProcessing {
        10 = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor
        10 {
            references.fieldName = header_image
            as = headerImage
        }

        20 = AUS\RelationProcessor\DataProcessing\RelationProcessor
        20 {
            field = tx_customerproduct_companies

            dataProcessing {
                10 = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor
                10 {
                    references.fieldName = header_image
                    as = headerImage
                }

                20 = AUS\RelationProcessor\DataProcessing\RelationProcessor
                20 {
                    field = tx_customercompany_product_family

                    dataProcessing {
                        10 = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor
                        10 {
                            references.fieldName = header_image
                            as = headerImage
                        }
                    }
                }
            }
        }
    }
}
````

# with ♥️ from anders und sehr GmbH

> If something did not work 😮  
> or you appreciate this Extension 🥰 let us know.

> We are hiring https://www.andersundsehr.com/karriere/

