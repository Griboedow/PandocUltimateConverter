local function colorize_mark_class(el)
    local MARK_COLOR = "yellow"
    for _, cls in ipairs(el.attr.classes) do
        if cls == "mark" then
            local style = el.attr.attributes["style"]
            el.attr.attributes["style"] = 
                (style and style .. ";" or "") .. "background-color:" .. MARK_COLOR .. ";"
            return el
        end
    end
    return el
end

function Span(el)
    return colorize_mark_class(el)
end

function Div(el)
    return colorize_mark_class(el)
end