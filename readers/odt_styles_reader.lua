Extensions = {
    smart = true,  
}

local json = require 'pandoc.json'

function ByteStringReader(input, opts)
    local doc = pandoc.read(input, "odt", opts)

    --print(pandoc.json.encode(doc))
    print(input)

    return pandoc.Pandoc(doc.content, doc.meta)
end