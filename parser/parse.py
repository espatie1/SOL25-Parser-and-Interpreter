#!/usr/bin/env python3
# Author: Pavel Stepanov (xstepa77)
import sys
import re
import argparse
import xml.etree.ElementTree as ET

# --- Definition of error codes ---
ERR_MISSING_PARAM = 10
ERR_OPEN_INPUT = 11
ERR_OPEN_OUTPUT = 12
ERR_LEXICAL = 21
ERR_SYNTACTIC = 22
ERR_MISSING_MAIN = 31
ERR_UNDEFINED_VAR = 32
ERR_ARITY = 33
ERR_VAR_COLLISION = 34
ERR_SEMANTIC_OTHER = 35
ERR_INTERNAL = 99

builtin_parents = {
    "Object": None,
    "Nil": "Object",
    "Integer": "Object",
    "String": "Object",
    "Block": "Object",
    "True": "Object",
    "False": "Object"
}

builtin_methods = {
    "Object": {"new", "from:","identicalTo:", "equalTo:", "asString", "isNumber", "isString", "isBlock", "isNil"},
    "Nil": {"asString"},
    "Integer": {"equalTo:", "greaterThan:", "plus:", "minus:", "multiplyBy:", "divBy:", "asString", "asInteger", "timesRepeat:"},
    "String": {"read", "print", "equalTo:", "asString", "asInteger", "concatenateWith:", "startsWith:", "endsBefore:"},
    "Block": {"value", "value:", "value:value:"},
    "True": {"not", "and:", "or:", "ifTrue:ifFalse:"},
    "False": {"not", "and:", "or:", "ifTrue:ifFalse:"}
}

def error_exit(code, message):
    print(message, file=sys.stderr)
    sys.exit(code)

class Token:
    def __init__(self, type_, value, pos):
        self.type = type_
        self.value = value
        self.pos = pos
    def __repr__(self):
        return f"Token({self.type}, {self.value}, {self.pos})"

def process_string_literal(literal):
    # Remove the enclosing single quotes
    content = literal[1:-1]
    result = ""
    i = 0
    while i < len(content):
        if content[i] == '\\':
            i += 1
            if i >= len(content):
                error_exit(ERR_LEXICAL, "Invalid escape sequence: unexpected end after '\\'")
            esc_char = content[i]
            if esc_char == "'":
                result += "'"
            elif esc_char == "n":
                result += "\n"
            elif esc_char == "\\":
                result += "\\"
            else:
                error_exit(ERR_LEXICAL, f"Invalid escape sequence: \\{esc_char}")
            i += 1
        else:
            # Check: character must have an ASCII code greater than 31
            if ord(content[i]) <= 31:
                error_exit(ERR_LEXICAL, f"Invalid character (code {ord(content[i])}) in string literal")
            result += content[i]
            i += 1
    return result

class Lexer:
    token_specification = [
        ('WHITESPACE',  r'\s+'),
        ('COMMENT',     r'"(?:\\.|[^"\\])*"'),   # block comment (non-nested)
        ('ASSIGN',      r':='),                 # assignment operator
        ('NUMBER',      r'[+-]?\d+'),           # integer with optional sign
        ('STRING',      r"'(?:\\.|[^'\\])*'"),   # string literal enclosed in single quotes
        ('IDENT',       r'[a-zA-Z_][a-zA-Z0-9_]*'),  # identifiers
        ('COLON',       r':'),                  # colon
        ('LBRACKET',    r'\['),                 # left square bracket
        ('RBRACKET',    r'\]'),                 # right square bracket
        ('LBRACE',      r'\{'),                 # left curly brace
        ('RBRACE',      r'\}'),                 # right curly brace
        ('LPAREN',      r'\('),                 # left parenthesis
        ('RPAREN',      r'\)'),                 # right parenthesis
        ('PIPE',        r'\|'),                 # vertical bar (parameter separator in block)
        ('DOT',         r'\.'),                 # dot (end of expression)
        ('OTHER',       r'.'),                  # any other character – error
    ]
    token_regex = '|'.join(f'(?P<{name}>{pattern})' for name, pattern in token_specification)
    master_pattern = re.compile(token_regex, re.DOTALL)

    def __init__(self, code):
        self.code = code
        self.tokens = []
    
    def tokenize(self):
        pos = 0
        while pos < len(self.code):
            match = self.master_pattern.match(self.code, pos)
            if not match:
                error_exit(ERR_LEXICAL, f"Unknown character at position {pos}")
            type_ = match.lastgroup
            value = match.group(type_)
            if type_ == 'WHITESPACE':
                # Skip whitespace characters
                pass
            elif type_ == 'COMMENT':
                # Store comment (e.g. for use in the description attribute)
                self.tokens.append(Token('COMMENT', value, pos))
            elif type_ == 'STRING':
                # Process escape sequences in string literal
                processed_value = process_string_literal(value)
                self.tokens.append(Token('STRING', processed_value, pos))
            elif type_ == 'OTHER':
                error_exit(ERR_LEXICAL, f"Invalid character: {value} at position {pos}")
            else:
                self.tokens.append(Token(type_, value, pos))
            pos = match.end()
        return self.tokens


# Definition of AST node classes
class ASTNode:
    pass

class Program(ASTNode):
    def __init__(self, classes, description=None):
        # classes: list of ClassDef objects
        # description: string – the first found comment (if any)
        self.classes = classes
        self.description = description

class ClassDef(ASTNode):
    def __init__(self, name, parent, methods):
        # name: class name (string)
        # parent: parent class name (string)
        # methods: list of Method objects
        self.name = name
        self.parent = parent
        self.methods = methods

class Method(ASTNode):
    def __init__(self, selector, block):
        # selector: method selector (string, e.g., "run", "plusOne:" or "compute:and:and:")
        # block: Block object – method body (block literal)
        self.selector = selector
        self.block = block

class Block(ASTNode):
    def __init__(self, parameters, assigns):
        # parameters: list of Parameter objects
        # assigns: list of Assignment objects – assignment commands inside the block
        self.parameters = parameters
        self.assigns = assigns

class Parameter(ASTNode):
    def __init__(self, name, order):
        # name: parameter name (string)
        # order: parameter order number (starting from 1)
        self.name = name
        self.order = order

class Assignment(ASTNode):
    def __init__(self, var, expr, order):
        # var: Var object – target variable
        # expr: expression (object Literal, Var, Block, Send, etc.)
        # order: order number of the assignment command in the block
        self.var = var
        self.expr = expr
        self.order = order

class Var(ASTNode):
    def __init__(self, name, pos):
        # name: variable name (string)
        # pos: position of the variable in the input code
        self.name = name
        self.pos = pos

class Literal(ASTNode):
    def __init__(self, literal_class, value, pos):
        # literal_class: string – class of the literal ("Integer", "String", "True", "False", "Nil", "class")
        # value: string – value of the literal
        # pos: position of the literal in the input code
        self.literal_class = literal_class
        self.value = value
        self.pos = pos

class Send(ASTNode):
    def __init__(self, selector, target, args, pos):
        # selector: message selector (string)
        # target: target expression (object Literal, Var, Block, Send, etc.)
        # args: list of argument expressions (objects Literal, Var, Block, Send, etc.)
        # pos: position of the message send in the input code
        self.selector = selector
        self.target = target
        self.args = args
        self.pos = pos


# Parser (Syntactic Analyzer)
class Parser:
    def __init__(self, tokens):
        # Initialize the parser with the given token list.
        # 'pos' tracks the current position in the token list.
        # 'last_token_end' stores the position immediately after the last consumed token.
        self.tokens = tokens
        self.pos = 0
        self.last_token_end = 0  # position immediately after the last consumed token

    @property
    def current_token(self):
        # Return the current token or None if we have reached the end.
        if self.pos < len(self.tokens):
            return self.tokens[self.pos]
        return None

    def error(self, message):
        # Report a syntactic error, including the position and value of the current token.
        token = self.current_token
        if token is None:
            pos_info = "end of input"
        else:
            pos_info = f"Token position: {token.pos}, token value: \"{token.value}\""
        error_exit(ERR_SYNTACTIC, f"Syntactic error ({ERR_SYNTACTIC}): {message} {pos_info}")

    def consume(self, expected_type=None):
        # Consume and return the current token.
        # If expected_type is provided, verify that the token's type matches it.
        # Update the current position and the last_token_end accordingly.
        token = self.current_token
        if token is None:
            self.error("Unexpected end of input")
        if expected_type and token.type != expected_type:
            self.error(f"Expected token of type {expected_type}, but got {token.type} ('{token.value}') at position {token.pos}")
        self.pos += 1
        self.last_token_end = token.pos + len(token.value)
        return token

    def parse(self):
        # Main entry point for parsing.
        # First, find the lexically first comment (if any) for use as the program description.
        description = None
        comment_tokens = [t for t in self.tokens if t.type == "COMMENT"]
        if comment_tokens:
            first_comment = min(comment_tokens, key=lambda t: t.pos)
            description = first_comment.value.strip('"')
        # Remove COMMENT tokens so they do not interfere with further syntactic analysis.
        self.tokens = [t for t in self.tokens if t.type != "COMMENT"]
        self.pos = 0  # Reset position after filtering tokens.
        classes = []
        # Parse class definitions until the token stream is exhausted.
        while self.current_token is not None:
            cls = self.parse_class()
            classes.append(cls)
        return Program(classes, description)

    def parse_class(self):
        """
        Parse a class definition using the grammar:
            ClassDef -> "class" IDENT ":" IDENT "{" { MethodDef } "}"
        """
        token = self.consume("IDENT")
        if token.value != "class":
            self.error(f"Expected keyword 'class', but got '{token.value}' at position {token.pos}")
        class_name_token = self.consume("IDENT")
        # Ensure that the class identifier starts with an uppercase letter and contains no underscores.
        if not class_name_token.value[0].isupper() or "_" in class_name_token.value:
            self.error(f"Invalid class identifier: {class_name_token.value}")
        class_name = class_name_token.value
        self.consume("COLON")
        parent_token = self.consume("IDENT")
        # Ensure that the parent class identifier is valid (uppercase and no underscores).
        if not parent_token.value[0].isupper() or "_" in parent_token.value:
            self.error(f"Invalid parent class identifier: {parent_token.value}")
        parent_name = parent_token.value
        self.consume("LBRACE")  # Consume the opening '{'
        methods = []
        # Parse all method definitions inside the class body.
        while self.current_token is not None and self.current_token.type != "RBRACE":
            method = self.parse_method()
            methods.append(method)
        self.consume("RBRACE")  # Consume the closing '}'
        return ClassDef(class_name, parent_name, methods)

    def parse_method(self):
        """
        Parse a method definition using the grammar:
            MethodDef -> Selector BlockLiteral
        """
        selector = self.parse_selector()
        block = self.parse_block()
        return Method(selector, block)

    def parse_selector(self):
        # Parse the method selector.
        # A selector starts with an identifier (which must begin with a lowercase letter)
        # and may be followed by one or more colon tokens and additional identifiers.
        token = self.consume("IDENT")
        if not token.value[0].islower():
            self.error(f"Invalid selector identifier: {token.value}")
        selector = token.value
        # Process additional parts of the selector if a colon is encountered.
        while self.current_token is not None and self.current_token.type == "COLON":
            # Ensure there is no space between the identifier and the colon.
            if self.current_token.pos != self.last_token_end:
                self.error("Invalid selector format: space between identifier and colon")
            colon_token = self.consume("COLON")
            selector += ":"
            # If the next token is an identifier, it must immediately follow the colon.
            if self.current_token is not None and self.current_token.type == "IDENT":
                if self.current_token.pos != self.last_token_end:
                    self.error("Invalid selector format: space between colon and identifier")
                token2 = self.consume("IDENT")
                selector += token2.value
            # If there is no identifier after the colon, keep the colon as part of the selector.
        # Certain reserved words are not allowed as selectors.
        if ":" not in selector and selector in {"self", "super", "true", "false", "nil", "class"}:
            self.error(f"Invalid selector identifier: {selector}")
        return selector

    def parse_block(self):
        """
        Parse a block literal using the grammar:
            BlockLiteral -> "[" BlockHeader BlockCommands "]"
        The block header contains parameter declarations and is separated from the block commands by a PIPE symbol.
        """
        lbracket = self.consume("LBRACKET")  # Consume the opening '['
        parameters = []
        assigns = []
        # Process block header: either a PIPE (no parameters) or parameter declarations.
        if self.current_token is not None and self.current_token.type == "PIPE":
            # No parameters; simply consume the PIPE.
            self.consume("PIPE")
        elif self.current_token is not None and self.current_token.type == "COLON":
            order = 1
            # Process one or more parameter declarations.
            while self.current_token is not None and self.current_token.type == "COLON":
                colon_token = self.consume("COLON")
                # Expect an identifier immediately following the colon.
                if self.current_token is None or self.current_token.type != "IDENT":
                    self.error("Expected an identifier after the colon in block literal")
                if self.current_token.pos != colon_token.pos + len(colon_token.value):
                    self.error("Invalid parameter declaration format: space between colon and identifier")
                param_token = self.consume("IDENT")
                # Ensure that the parameter name starts with a lowercase letter or an underscore.
                if not (param_token.value[0].islower() or param_token.value[0] == '_'):
                    self.error(f"Invalid parameter identifier: {param_token.value}")
                # Reserved identifiers cannot be used as parameters.
                if param_token.value in {"self", "super", "true", "false", "nil", "class"}:
                    self.error(f"Invalid parameter identifier: {param_token.value}")
                parameters.append(Parameter(param_token.value, order))
                order += 1
            # Expect a PIPE symbol after the parameter declarations.
            if self.current_token is None or self.current_token.type != "PIPE":
                self.error("Expected '|' after parameter declarations in block literal")
            self.consume("PIPE")
        else:
            self.error("Expected parameter declarations or '|' symbol in block literal")
        # Parse block commands: a sequence of assignment statements, each terminated by a DOT.
        order = 1
        while self.current_token is not None and self.current_token.type != "RBRACKET":
            assign = self.parse_assignment(order)
            assigns.append(assign)
            order += 1
        self.consume("RBRACKET")  # Consume the closing ']'
        return Block(parameters, assigns)

    def parse_assignment(self, order):
        """
        Parse an assignment statement using the grammar:
            Assignment -> IDENT ASSIGN Expression DOT
        Returns an Assignment AST node.
        """
        var_token = self.consume("IDENT")
        # Reserved identifiers cannot be used on the left-hand side.
        if var_token.value in {"class", "self", "super", "nil", "true", "false"}:
            self.error(f"Cannot assign a value to a reserved identifier: {var_token.value}")
        var_node = Var(var_token.value, var_token.pos)
        self.consume("ASSIGN")  # Consume the assignment operator ':='
        expr_node = self.parse_expr()
        self.consume("DOT")     # Consume the dot that terminates the assignment
        return Assignment(var_node, expr_node, order)

    def parse_expr(self):
        # Parse an expression by first parsing its base and then any trailing message sends.
        base = self.parse_expr_base()
        return self.parse_expr_tail(base)

    def parse_expr_base(self):
        # Parse the basic part of an expression.
        token = self.current_token
        if token is None:
            self.error("Unexpected end of input in expression")
        if token.type == "LPAREN":
            # Parenthesized expression.
            self.consume("LPAREN")
            expr = self.parse_expr()
            self.consume("RPAREN")
            return expr
        elif token.type == "LBRACKET":
            # Block literal.
            return self.parse_block()
        elif token.type == "NUMBER":
            token = self.consume("NUMBER")
            return Literal("Integer", token.value, token.pos)
        elif token.type == "STRING":
            token = self.consume("STRING")
            return Literal("String", token.value, token.pos)
        elif token.type == "IDENT":
            token = self.consume("IDENT")
            # Handle reserved literals and class references.
            if token.value == "true":
                return Literal("True", "true", token.pos)
            elif token.value == "false":
                return Literal("False", "false", token.pos)
            elif token.value == "nil":
                return Literal("Nil", "nil", token.pos)
            elif token.value[0].isupper():
                # A class literal if identifier starts with an uppercase letter.
                return Literal("class", token.value, token.pos)
            else:
                # Otherwise, it's a variable.
                return Var(token.value, token.pos)
        else:
            self.error(f"Unexpected token in expression: {token.type} ('{token.value}') at position {token.pos}")

    def parse_expr_tail(self, base):
        # Parse additional message send parts that may follow the base expression.
        while self.current_token is not None and self.current_token.type in ("IDENT", "COLON"):
            if self.current_token.type == "IDENT":
                # If the next token (after current IDENT) is a COLON, then it's a send.
                if self.pos + 1 < len(self.tokens) and self.tokens[self.pos + 1].type == "COLON":
                    base = self._parse_send_tail(base)
                else:
                    token = self.consume("IDENT")
                    base = Send(token.value, base, [], token.pos)
            elif self.current_token.type == "COLON":
                base = self._parse_send_tail(base)
        return base

    def _parse_send_tail(self, base):
        """
        Parse the tail of a message send expression using the grammar:
            SendTail -> IDENT { COLON Expression [IDENT]? }
        Example: compute: 3 and: 2 and
        Returns a Send AST node.
        """
        selector_parts = []
        args = []
        # Process the first component of the selector.
        if self.current_token.type == "IDENT":
            ident_token = self.consume("IDENT")
            selector_parts.append(ident_token.value)
            # Ensure that the colon follows immediately after the identifier.
            if self.current_token is None or self.current_token.type != "COLON" or self.current_token.pos != ident_token.pos + len(ident_token.value):
                self.error("Invalid selector format: space between identifier and colon")
            self.consume("COLON")
            selector_parts.append(":")
            arg_expr = self.parse_expr_base()
            args.append(arg_expr)
        else:
            # If the selector does not start with an IDENT, expect a COLON immediately.
            if self.current_token is None or self.current_token.type != "COLON":
                self.error("Invalid selector format: expected ':'")
            self.consume("COLON")
            selector_parts.append(":")
            arg_expr = self.parse_expr_base()
            args.append(arg_expr)
        # Process any subsequent components of the selector.
        while (self.current_token is not None and 
               self.current_token.type == "IDENT" and 
               self.pos + 1 < len(self.tokens) and 
               self.tokens[self.pos + 1].type == "COLON"):
            ident_token = self.consume("IDENT")
            if self.current_token is None or self.current_token.type != "COLON" or self.current_token.pos != ident_token.pos + len(ident_token.value):
                self.error("Invalid selector format: space between identifier and colon")
            self.consume("COLON")
            selector_parts.append(ident_token.value)
            selector_parts.append(":")
            arg_expr = self.parse_expr_base()
            args.append(arg_expr)
        selector = "".join(selector_parts)
        # Use the position of the base expression for the Send node.
        return Send(selector, base, args, base.pos)

    def parse_send(self):
        """
        Parse a message send expression enclosed in parentheses using the grammar:
            SendExpr -> "(" Expression SendTail ")"
        Example: (self compute: 3 and: 2 and: 5)
        Returns a Send AST node.
        """
        self.consume("LPAREN")  # Consume the opening '('
        target_expr = self.parse_expr()
        # The selector part is mandatory.
        if self.current_token is None or self.current_token.type != "IDENT":
            self.error("Expected a selector in the message send expression")
        selector_parts = []
        token = self.consume("IDENT")
        selector_parts.append(token.value)
        args = []
        while self.current_token is not None and self.current_token.type == "COLON":
            self.consume("COLON")
            selector_parts.append(":")
            # Each colon must be followed by an expression argument.
            arg_expr = self.parse_expr()
            args.append(arg_expr)
            # If an IDENT immediately follows, it becomes part of the selector.
            if self.current_token is not None and self.current_token.type == "IDENT":
                token = self.consume("IDENT")
                selector_parts.append(token.value)
        selector = "".join(selector_parts)
        self.consume("RPAREN")  # Consume the closing ')'
        return Send(selector, target_expr, args, target_expr.pos)


class SemanticAnalyzer:
    def __init__(self):
        # dynamic_attrs stores the set of dynamically created instance attributes
        # for each user-defined class. The keys are class names and values are sets.
        self.dynamic_attrs = {}

    def lookup_method(self, cls_name, selector):
        """
        Recursively look up a method with the given selector in the class hierarchy.
        For user-defined classes, first check methods defined in the class (self.class_map).
        If not found, check in the parent class; for built-in classes, call lookup_builtin_method.
        """
        if cls_name in self.class_map:
            cls_node = self.class_map[cls_name]
            # Check if the method is explicitly defined in the class.
            if any(m.selector == selector for m in cls_node.methods):
                return True
            # If not, recursively check in the parent class.
            parent = cls_node.parent
            if parent in self.class_map:
                return self.lookup_method(parent, selector)
            else:
                # For a parent that is not user-defined, check in the built-in methods.
                return self.lookup_builtin_method(parent, selector)
        else:
            # If the class is built-in, use the built-in lookup.
            return self.lookup_builtin_method(cls_name, selector)

    def lookup_builtin_method(self, cls_name, selector):
        """
        Recursively look up a method for a built-in class using the builtin_methods dictionary.
        If the method is not found in the current class, use builtin_parents to move upward in the hierarchy.
        """
        if selector in builtin_methods.get(cls_name, set()):
            return True
        parent = builtin_parents.get(cls_name, None)
        if parent is not None:
            return self.lookup_builtin_method(parent, selector)
        return False

    def collect_class_dynamic_attributes(self, cls):
        """
        Collect dynamic attributes from all methods of the given class.
        This function iterates over each method of the class and
        collects dynamic attributes from its block (method body).
        """
        for method in cls.methods:
            self.collect_dynamic_attributes(method.block, cls)

    def analyze(self, program: Program):
        """
        Perform semantic analysis on the entire program.
        This includes checking for duplicate class and method definitions,
        verifying the presence of the Main class with a parameterless run method,
        ensuring that all parent classes are defined, checking for circular inheritance,
        initializing the dynamic attributes container, collecting dynamic attributes
        from all methods, and finally analyzing each block within the methods.
        """
        # Check for duplicate class definitions.
        seen = {}
        for cls in program.classes:
            if cls.name in seen:
                error_exit(ERR_SEMANTIC_OTHER, f"Duplicate class definition: {cls.name}")
            seen[cls.name] = cls

        # Check for duplicate method definitions within each class.
        for cls in program.classes:
            seen_methods = {}
            for method in cls.methods:
                if method.selector in seen_methods:
                    error_exit(ERR_SEMANTIC_OTHER, f"Duplicate method definition {method.selector} in class {cls.name}")
                seen_methods[method.selector] = method

        # Ensure that the Main class with a run method (no parameters) exists.
        main_found = False
        for cls in program.classes:
            if cls.name == "Main":
                for method in cls.methods:
                    if method.selector == "run":
                        if len(method.block.parameters) != 0:
                            error_exit(ERR_ARITY, "The run method in the Main class must have no parameters")
                        main_found = True
        if not main_found:
            error_exit(ERR_MISSING_MAIN, "Missing Main class with run method")

        # Save the set of defined classes and create a mapping from class names to class nodes.
        self.global_classes = set(seen.keys())
        self.class_map = seen
        builtin_classes = {"Object", "Integer", "String", "Nil", "True", "False", "Block"}

        # Check that every parent class is defined (either as a user-defined class or built-in).
        for cls in program.classes:
            if cls.parent != "Object" and cls.parent not in self.global_classes and cls.parent not in builtin_classes:
                error_exit(ERR_UNDEFINED_VAR, f"Undefined parent class: {cls.parent}")

        # Check for circular inheritance in the class hierarchy.
        self.check_circular_inheritance()

        # Initialize dynamic attributes for each user-defined class.
        for cls in program.classes:
            self.dynamic_attrs[cls.name] = set()

        # Perform a preliminary pass: collect all dynamic attributes from each class's methods.
        for cls in program.classes:
            self.collect_class_dynamic_attributes(cls)

        # Now analyze every block in each method, using the gathered dynamic attributes.
        for cls in program.classes:
            for method in cls.methods:
                self.analyze_block(method.block, current_class=cls)

    def check_circular_inheritance(self):
        """
        Check for circular inheritance in the class hierarchy.
        Each class is marked with a state:
          0 - not visited,
          1 - currently being visited,
          2 - completely processed.
        If during the DFS a class in the visiting state is encountered, a circular inheritance is detected.
        """
        visited = {}  # 0 - not visited, 1 - visiting, 2 - processed
        for cls_name in self.class_map:
            if visited.get(cls_name, 0) == 0:
                if self._dfs_check(cls_name, visited):
                    error_exit(ERR_SEMANTIC_OTHER, f"Circular inheritance detected: {cls_name}")

    def _dfs_check(self, cls_name, visited):
        # Depth-first search for circular inheritance.
        visited[cls_name] = 1
        cls = self.class_map[cls_name]
        parent = cls.parent
        if parent in self.class_map:
            state = visited.get(parent, 0)
            if state == 1:
                return True
            if state == 0:
                if self._dfs_check(parent, visited):
                    return True
        visited[cls_name] = 2
        return False

    def collect_dynamic_attributes_expr(self, expr, current_class):
        """
        Recursively traverse an expression (AST node) to collect dynamic attributes.
        If the expression is a message send where the target is 'self' and the selector ends with a colon,
        the attribute name (selector without the colon) is added to the dynamic attributes set for the class.
        """
        if isinstance(expr, Send):
            if isinstance(expr.target, Var) and expr.target.name == "self" and expr.selector.endswith(":"):
                attr_name = expr.selector[:-1]
                self.dynamic_attrs[current_class.name].add(attr_name)
            # Recurse into the target and each argument.
            self.collect_dynamic_attributes_expr(expr.target, current_class)
            for arg in expr.args:
                self.collect_dynamic_attributes_expr(arg, current_class)
        elif isinstance(expr, Block):
            # Recurse into blocks.
            self.collect_dynamic_attributes(expr, current_class)
        # For Var and Literal nodes, no dynamic attributes are collected.

    def collect_dynamic_attributes(self, block: Block, current_class):
        """
        Traverse all assignment expressions in a block and collect dynamic attributes.
        This ensures that any setter call (self attr: ...) is registered before semantic analysis.
        """
        for assign in block.assigns:
            self.collect_dynamic_attributes_expr(assign.expr, current_class)

    def analyze_block(self, block: Block, current_class):
        """
        Analyze a block (method body) for semantic correctness.
        This includes:
          - Checking for duplicate formal parameters.
          - Setting up an environment mapping for variables.
          - Collecting dynamic attributes within the block.
          - Analyzing each assignment expression.
        """
        seen_params = set()
        for param in block.parameters:
            if param.name in seen_params:
                error_exit(ERR_SEMANTIC_OTHER, f"Duplicate formal parameter: {param.name}")
            seen_params.add(param.name)
        
        # Environment mapping: formal parameters are marked as "param" and 'self' is always available.
        env = {param.name: "param" for param in block.parameters}
        env["self"] = "param"
        
        # Collect dynamic attributes from the block (e.g., setter calls).
        self.collect_dynamic_attributes(block, current_class)
        
        # Process each assignment statement in the block.
        for assign in block.assigns:
            if assign.var.name in env and env[assign.var.name] == "param":
                error_exit(ERR_VAR_COLLISION, f"Attempt to assign to a formal parameter: {assign.var.name}")
            self.analyze_expr(assign.expr, env, current_class)
            if assign.var.name not in env:
                env[assign.var.name] = "local"

    def analyze_expr(self, expr, env, current_class):
        """
        Analyze an expression for semantic correctness.
        The analysis differs based on the type of expression:
          - For variables (Var), check that they are initialized.
          - For literals (Literal), if they represent a class reference, verify that the class exists.
          - For message sends (Send), analyze the target and arguments, check arity,
            and process dynamic attribute access when the target is 'self'.
        """
        if isinstance(expr, Var):
            if expr.name not in env:
                if expr.name[0].isupper():
                    if expr.name not in self.global_classes:
                        error_exit(ERR_UNDEFINED_VAR, f"Usage of non-existent class: {expr.name}")
                else:
                    error_exit(ERR_UNDEFINED_VAR, f"Usage of uninitialized variable: {expr.name}")
        elif isinstance(expr, Literal):
            # If the literal is a reference to a class, check that the class is defined.
            if expr.literal_class == "class":
                if expr.value not in self.global_classes and expr.value not in builtin_methods:
                    error_exit(ERR_UNDEFINED_VAR, f"Usage of non-existent class: {expr.value}")
            # Other literals are considered valid.
            pass
        elif isinstance(expr, Send):
            reserved = {"self", "super", "true", "false", "nil", "class"}
            if expr.selector in reserved:
                error_exit(ERR_SYNTACTIC, f"Invalid selector identifier: {expr.selector}")
            # Recursively analyze the target and argument expressions.
            self.analyze_expr(expr.target, env, current_class)
            for arg in expr.args:
                self.analyze_expr(arg, env, current_class)
            # Special handling when the message is sent to 'self'.
            if isinstance(expr.target, Var) and expr.target.name == "self":
                method_found = None
                # Look for an explicitly defined method in the current class.
                for m in current_class.methods:
                    if m.selector == expr.selector:
                        method_found = m
                        break
                if method_found is not None:
                    expected_arity = len(method_found.block.parameters)
                    actual_arity = len(expr.args)
                    if expected_arity != actual_arity:
                        error_exit(ERR_ARITY, f"Incorrect arity for method call {expr.selector}: expected {expected_arity}, got {actual_arity}")
                else:
                    # Handle dynamic attribute access via self.
                    if expr.selector.endswith(":"):
                        # This is an attribute setter; it must have exactly one argument.
                        if len(expr.args) != 1:
                            error_exit(ERR_ARITY, f"Incorrect arity for attribute setter {expr.selector}: expected 1, got {len(expr.args)}")
                        attr_name = expr.selector[:-1]
                        self.dynamic_attrs[current_class.name].add(attr_name)
                    else:
                        # This is an attribute getter; it must have zero arguments, and the attribute must be registered.
                        if len(expr.args) != 0:
                            error_exit(ERR_ARITY, f"Incorrect arity for attribute getter {expr.selector}: expected 0, got {len(expr.args)}")
                        attr_name = expr.selector
                        if attr_name not in self.dynamic_attrs[current_class.name]:
                            error_exit(
                                ERR_UNDEFINED_VAR,
                                f"Semantic Error [ERR_UNDEFINED_VAR]: Usage of non-existent attribute in class '{current_class.name}: {attr_name}'. " \
                                f"Location: {getattr(expr, 'pos', 'unknown')}. " \
                                f"Context: {repr(expr)}. " \
                                f"Environment: {env}"
                            )
            # If the target is a class literal, check the method against built-in methods.
            if isinstance(expr.target, Literal) and expr.target.literal_class == "class":
                cls_name = expr.target.value
                if not self.lookup_method(cls_name, expr.selector):
                    error_exit(
                        ERR_UNDEFINED_VAR,
                        f"Semantic Error [ERR_UNDEFINED_VAR]: Usage of non-existent method in class '{cls_name}': '{expr.selector}'. " \
                        f"Location: {getattr(expr, 'pos', 'unknown')}. " \
                        f"Context: {repr(expr)}. " \
                        f"Environment: {env}"
                    )
            # If the target is a class name (Var starting with an uppercase letter), check as above.
            if isinstance(expr.target, Var) and expr.target.name[0].isupper():
                cls_name = expr.target.name
                if not self.lookup_method(cls_name, expr.selector):
                    error_exit(
                        ERR_UNDEFINED_VAR,
                        f"Semantic Error [ERR_UNDEFINED_VAR]: Usage of non-existent method in class '{cls_name}': '{expr.selector}'. " \
                        f"Location: {getattr(expr, 'pos', 'unknown')}. " \
                        f"Context: {repr(expr)}. " \
                        f"Environment: {env}"
                    )

# --- XML Generation ---
def generate_xml(program):
    # Root element <program> with the mandatory attribute language="SOL25"
    root = ET.Element("program", language="SOL25")
    
    # If there is a description (the first comment), convert spaces to &nbsp;
    if program.description:
        description_str = program.description.replace(" ", "&nbsp;")
        root.attrib["description"] = description_str

    # Process all classes
    for cls in program.classes:
        class_elem = ET.SubElement(root, "class", name=cls.name, parent=cls.parent)
        # Process class methods
        for method in cls.methods:
            method_elem = ET.SubElement(class_elem, "method", selector=method.selector)
            # Generate XML for the block (method body)
            block_elem = generate_xml_block(method.block)
            method_elem.append(block_elem)

    xml_str = ET.tostring(root, encoding="utf-8").decode("utf-8")
    # Apply replacement only within literal elements
    xml_str = replace_in_literals(xml_str)
    xml_declaration = '<?xml version="1.0" encoding="UTF-8"?>\n'
    return xml_declaration + xml_str


def generate_xml_block(block):
    block_elem = ET.Element("block", arity=str(len(block.parameters)))
    # Process parameters, sorted by order
    for param in sorted(block.parameters, key=lambda p: p.order):
        ET.SubElement(block_elem, "parameter", name=param.name, order=str(param.order))
    
    # Process assignment statements
    for assign in sorted(block.assigns, key=lambda a: a.order):
        assign_elem = ET.SubElement(block_elem, "assign", order=str(assign.order))
        ET.SubElement(assign_elem, "var", name=assign.var.name)
        expr_elem = ET.SubElement(assign_elem, "expr")
        generate_xml_expr(assign.expr, expr_elem)
    
    return block_elem

def generate_xml_expr(expr, parent_elem):
    if isinstance(expr, Literal):
        value = expr.value
        # If the literal is a string, replace apostrophes with the XML entity &apos;
        if expr.literal_class == "String":
            value = value.replace("'", "&apos;")
            value = value.replace("\\", "\\\\")
        ET.SubElement(parent_elem, "literal", **{"class": expr.literal_class, "value": value})
    elif isinstance(expr, Var):
        ET.SubElement(parent_elem, "var", name=expr.name)
    elif isinstance(expr, Block):
        block_elem = generate_xml_block(expr)
        parent_elem.append(block_elem)
    elif isinstance(expr, Send):
        send_elem = ET.SubElement(parent_elem, "send", selector=expr.selector)
        # The first child element is the target expression of the message send
        target_expr_elem = ET.SubElement(send_elem, "expr")
        generate_xml_expr(expr.target, target_expr_elem)
        # Then process the arguments. For each argument, an <arg> element is created with an 'order' attribute
        for index, arg_expr in enumerate(expr.args, start=1):
            arg_elem = ET.SubElement(send_elem, "arg", order=str(index))
            arg_expr_elem = ET.SubElement(arg_elem, "expr")
            generate_xml_expr(arg_expr, arg_expr_elem)
    else:
        error_exit(ERR_INTERNAL, f"Unknown expression type during XML generation: {expr}")
        
def replace_in_literals(xml_str):
    # Regular expression searches for the <literal ... value="..." ...> tag
    pattern = re.compile(r'(<literal\b[^>]*\svalue=")([^"]*)(")')
    def replacer(match):
        prefix = match.group(1)
        value = match.group(2)
        suffix = match.group(3)
        # Perform replacement only in the found value
        value = value.replace("&amp;apos;", "\\&apos;").replace("&#10;", "\\n")
        return f'{prefix}{value}{suffix}'
    return pattern.sub(replacer, xml_str)

# --- Main Function ---
def main():
    # Manually parse command line arguments
    argv = sys.argv
    if len(argv) == 2 and argv[1] in ("-h", "--help"):
        print("Usage: parse.py [--help]\n"
              "The script analyzes SOL25 source code, performs lexical, syntactic and semantic analysis,\n"
              "and then outputs an XML representation of the abstract syntax tree.")
        sys.exit(0)
    elif len(argv) != 1:
        error_exit(ERR_MISSING_PARAM, "Incorrect number of parameters")
    
    # Reading source code from standard input
    try:
        code = sys.stdin.read()
    except Exception as e:
        error_exit(ERR_OPEN_INPUT, f"Error reading standard input: {e}")

    # Lexical analysis
    lexer_obj = Lexer(code)
    tokens = lexer_obj.tokenize()

    # Syntactic analysis
    parser_obj = Parser(tokens)
    ast = parser_obj.parse()

    # Semantic analysis
    sem_analyzer = SemanticAnalyzer()
    sem_analyzer.analyze(ast)

    # XML Generation
    xml_output = generate_xml(ast)
    xml_output = xml_output.replace("&amp;nbsp;", " ")
    print(xml_output)

if __name__ == "__main__":
    main()
