function Header(el)
    -- Mediaiwki headings start from level 2 (== Heading ==)
    el.level = el.level + 1
    return el
end